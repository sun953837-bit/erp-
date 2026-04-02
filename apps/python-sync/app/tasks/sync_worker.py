from __future__ import annotations

import json
import logging
from datetime import datetime
from typing import Any

from celery import shared_task
from sqlalchemy import or_, select

from app.core.config import settings
from app.core.database import SessionLocal
from app.models.raw_listing import RawListing
from app.models.raw_order import RawOrder
from app.models.raw_refund import RawRefund
from app.models.raw_service import RawService
from app.models.sync_task import SyncTask
from app.services.sync_executor import (
    apply_execution_result,
    create_receipt,
    execute_task_action,
)
from app.services.channel_account_sync import maybe_write_back_auth_status
from app.services.sync_state_machine import (
    InvalidStatusTransitionError,
    SyncStateMachine,
    SyncStatus,
)

logger = logging.getLogger(__name__)


def _fetch_runnable_task_ids(batch_size: int) -> list[int]:
    now = datetime.utcnow()
    with SessionLocal() as session:
        stmt = (
            select(SyncTask.id)
            .where(
                SyncTask.status.in_([SyncStatus.PENDING.value, SyncStatus.RETRYING.value]),
                or_(SyncTask.next_retry_at.is_(None), SyncTask.next_retry_at <= now),
            )
            .order_by(SyncTask.id.asc())
            .limit(batch_size)
        )
        return [row[0] for row in session.execute(stmt).all()]


def _mark_running(task: SyncTask) -> None:
    SyncStateMachine.validate_transition(task.status, SyncStatus.RUNNING.value)
    task.status = SyncStatus.RUNNING.value
    task.updated_at = datetime.utcnow()


def _process_task(task_id: int) -> None:
    with SessionLocal() as session:
        task = session.get(SyncTask, task_id)
        if task is None:
            return
        if task.status not in {SyncStatus.PENDING.value, SyncStatus.RETRYING.value}:
            return

        try:
            _mark_running(task)
        except InvalidStatusTransitionError as exc:
            logger.warning("Skip task %s due invalid transition: %s", task_id, exc)
            return

        payload_json = task.payload_json or {}
        task_payload = payload_json if isinstance(payload_json, dict) else {"value": payload_json}
        request_payload = {
            "task_no": task.task_no,
            "task_type": task.task_type,
            "payload": task_payload,
        }
        request_log = create_receipt(
            sync_task_id=task.id,
            platform_code=task.platform_code,
            phase="REQUEST",
            endpoint=task.task_type,
            request_payload=_sanitize_payload(request_payload, settings.worker_max_payload_bytes, "request"),
            response_payload=None,
            response=None,
            http_status=None,
        )
        session.add(request_log)
        session.flush()

        request_payload_size = _payload_size_bytes(request_payload)
        if request_payload_size > settings.worker_max_payload_bytes:
            result = _build_worker_failure_result(
                code="WORKER_PAYLOAD_TOO_LARGE",
                message=(
                    f"request payload too large: {request_payload_size} bytes "
                    f"(limit: {settings.worker_max_payload_bytes} bytes)"
                ),
                detail={
                    "payload_size_bytes": request_payload_size,
                    "payload_limit_bytes": settings.worker_max_payload_bytes,
                    "phase": "request",
                },
            )
            response_log = create_receipt(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                phase="RESPONSE",
                endpoint=task.task_type,
                request_payload=_sanitize_payload(request_payload, settings.worker_max_payload_bytes, "request"),
                response_payload=_sanitize_payload(
                    result.get("raw_payload"),
                    settings.worker_max_payload_bytes,
                    "response_payload",
                ),
                response=_sanitize_payload(result, settings.worker_max_payload_bytes, "response"),
                http_status=413,
            )
            session.add(response_log)
            _apply_result_or_fallback(task, result)
            task.updated_at = datetime.utcnow()
            session.commit()
            return

        adapter_payload = dict(task_payload)
        adapter_payload["biz_id"] = task.biz_id
        adapter_payload["shop_id"] = task.shop_id
        adapter_payload["platform_code"] = task.platform_code
        adapter_payload["site_code"] = task.site_code
        adapter_payload["task_no"] = task.task_no

        endpoint, result = execute_task_action(task, adapter_payload)
        channel_account_sync_result = maybe_write_back_auth_status(task, result)
        if channel_account_sync_result is not None:
            result = dict(result)
            result["channel_account_sync"] = channel_account_sync_result

        response_payload_size = _payload_size_bytes(result.get("raw_payload"))
        if response_payload_size > settings.worker_max_payload_bytes:
            result = _build_worker_failure_result(
                code="WORKER_PAYLOAD_TOO_LARGE",
                message=(
                    f"response payload too large: {response_payload_size} bytes "
                    f"(limit: {settings.worker_max_payload_bytes} bytes)"
                ),
                detail={
                    "payload_size_bytes": response_payload_size,
                    "payload_limit_bytes": settings.worker_max_payload_bytes,
                    "phase": "response",
                    "endpoint": endpoint,
                },
            )

        response_log = create_receipt(
            sync_task_id=task.id,
            platform_code=task.platform_code,
            phase="RESPONSE",
            endpoint=endpoint,
            request_payload=_sanitize_payload(adapter_payload, settings.worker_max_payload_bytes, "adapter_request"),
            response_payload=_sanitize_payload(
                result.get("raw_payload"),
                settings.worker_max_payload_bytes,
                "response_payload",
            ),
            response=_sanitize_payload(result, settings.worker_max_payload_bytes, "response"),
            http_status=200,
        )
        session.add(response_log)

        _persist_raw_pull_record(
            session=session,
            task=task,
            endpoint=endpoint,
            result=result,
            request_payload=adapter_payload,
        )

        _apply_result_or_fallback(task, result)

        task.updated_at = datetime.utcnow()
        session.commit()


def _persist_raw_pull_record(
    *,
    session,
    task: SyncTask,
    endpoint: str,
    result: dict,
    request_payload: dict,
) -> None:
    if endpoint not in {"pull_orders", "pull_refunds", "pull_listings", "pull_services"}:
        return

    if not bool(result.get("success")):
        return

    now = datetime.utcnow()
    raw_payload = result.get("raw_payload")
    external_id = result.get("external_id")
    all_records = _extract_pull_records(raw_payload)
    max_records = max(1, int(settings.worker_max_pull_records_per_task))
    records = all_records[:max_records]
    dropped_count = max(0, len(all_records) - len(records))

    if dropped_count > 0:
        logger.warning(
            "Task %s endpoint %s records truncated from %s to %s",
            task.task_no,
            endpoint,
            len(all_records),
            len(records),
        )

    if endpoint == "pull_orders":
        _persist_raw_order_rows(
            session=session,
            task=task,
            request_payload=request_payload,
            result=result,
            raw_payload=raw_payload,
            now=now,
            records=records,
            fallback_external_id=external_id,
            dropped_count=dropped_count,
        )
        return

    if endpoint == "pull_refunds":
        _persist_raw_refund_rows(
            session=session,
            task=task,
            request_payload=request_payload,
            result=result,
            raw_payload=raw_payload,
            now=now,
            records=records,
            fallback_external_id=external_id,
            dropped_count=dropped_count,
        )
        return

    if endpoint == "pull_services":
        _persist_raw_service_rows(
            session=session,
            task=task,
            request_payload=request_payload,
            result=result,
            raw_payload=raw_payload,
            now=now,
            records=records,
            fallback_external_id=external_id,
            dropped_count=dropped_count,
        )
        return

    _persist_raw_listing_rows(
        session=session,
        task=task,
        request_payload=request_payload,
        result=result,
        raw_payload=raw_payload,
        now=now,
        records=records,
        fallback_external_id=external_id,
        dropped_count=dropped_count,
    )


def _persist_raw_order_rows(
    *,
    session,
    task: SyncTask,
    request_payload: dict,
    result: dict,
    raw_payload: Any,
    now: datetime,
    records: list[dict[str, Any]],
    fallback_external_id: Any,
    dropped_count: int,
) -> None:
    existing_event_keys = _load_existing_event_keys(session, RawOrder, task.id)

    if not records:
        if task.task_no in existing_event_keys:
            return
        session.add(
            RawOrder(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_order_id=str(fallback_external_id) if fallback_external_id else None,
                payload_json=_build_row_payload(
                    request_payload,
                    result,
                    raw_payload,
                    None,
                    0,
                    0,
                    dropped_count,
                ),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    total = len(records)
    for index, record in enumerate(records, start=1):
        event_key = f"{task.task_no}:{index}"
        if event_key in existing_event_keys:
            continue

        external_order_id = _pick_record_id(
            record,
            ["external_order_id", "order_id", "id", "biz_order_id", "trade_no"],
            fallback_external_id,
            index,
        )
        session.add(
            RawOrder(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=event_key,
                external_order_id=external_order_id,
                payload_json=_build_row_payload(
                    request_payload,
                    result,
                    raw_payload,
                    record,
                    index,
                    total,
                    dropped_count,
                ),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )


def _persist_raw_refund_rows(
    *,
    session,
    task: SyncTask,
    request_payload: dict,
    result: dict,
    raw_payload: Any,
    now: datetime,
    records: list[dict[str, Any]],
    fallback_external_id: Any,
    dropped_count: int,
) -> None:
    existing_event_keys = _load_existing_event_keys(session, RawRefund, task.id)

    if not records:
        if task.task_no in existing_event_keys:
            return
        session.add(
            RawRefund(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_refund_id=str(fallback_external_id) if fallback_external_id else None,
                payload_json=_build_row_payload(
                    request_payload,
                    result,
                    raw_payload,
                    None,
                    0,
                    0,
                    dropped_count,
                ),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    total = len(records)
    for index, record in enumerate(records, start=1):
        event_key = f"{task.task_no}:{index}"
        if event_key in existing_event_keys:
            continue

        external_refund_id = _pick_record_id(
            record,
            ["external_refund_id", "refund_id", "id"],
            fallback_external_id,
            index,
        )
        session.add(
            RawRefund(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=event_key,
                external_refund_id=external_refund_id,
                payload_json=_build_row_payload(
                    request_payload,
                    result,
                    raw_payload,
                    record,
                    index,
                    total,
                    dropped_count,
                ),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )


def _persist_raw_service_rows(
    *,
    session,
    task: SyncTask,
    request_payload: dict,
    result: dict,
    raw_payload: Any,
    now: datetime,
    records: list[dict[str, Any]],
    fallback_external_id: Any,
    dropped_count: int,
) -> None:
    existing_event_keys = _load_existing_event_keys(session, RawService, task.id)

    if not records:
        if task.task_no in existing_event_keys:
            return
        session.add(
            RawService(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_service_id=str(fallback_external_id) if fallback_external_id else None,
                payload_json=_build_row_payload(
                    request_payload,
                    result,
                    raw_payload,
                    None,
                    0,
                    0,
                    dropped_count,
                ),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    total = len(records)
    for index, record in enumerate(records, start=1):
        event_key = f"{task.task_no}:{index}"
        if event_key in existing_event_keys:
            continue

        external_service_id = _pick_record_id(
            record,
            ["external_service_id", "service_id", "id"],
            fallback_external_id,
            index,
        )
        session.add(
            RawService(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=event_key,
                external_service_id=external_service_id,
                payload_json=_build_row_payload(
                    request_payload,
                    result,
                    raw_payload,
                    record,
                    index,
                    total,
                    dropped_count,
                ),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )


def _persist_raw_listing_rows(
    *,
    session,
    task: SyncTask,
    request_payload: dict,
    result: dict,
    raw_payload: Any,
    now: datetime,
    records: list[dict[str, Any]],
    fallback_external_id: Any,
    dropped_count: int,
) -> None:
    existing_event_keys = _load_existing_event_keys(session, RawListing, task.id)

    if not records:
        if task.task_no in existing_event_keys:
            return
        session.add(
            RawListing(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_listing_id=str(fallback_external_id) if fallback_external_id else None,
                payload_json=_build_row_payload(
                    request_payload,
                    result,
                    raw_payload,
                    None,
                    0,
                    0,
                    dropped_count,
                ),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    total = len(records)
    for index, record in enumerate(records, start=1):
        event_key = f"{task.task_no}:{index}"
        if event_key in existing_event_keys:
            continue

        external_listing_id = _pick_record_id(
            record,
            ["external_listing_id", "listing_id", "id"],
            fallback_external_id,
            index,
        )
        session.add(
            RawListing(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=event_key,
                external_listing_id=external_listing_id,
                payload_json=_build_row_payload(
                    request_payload,
                    result,
                    raw_payload,
                    record,
                    index,
                    total,
                    dropped_count,
                ),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )


def _extract_pull_records(raw_payload: Any) -> list[dict[str, Any]]:
    if isinstance(raw_payload, list):
        return [item for item in raw_payload if isinstance(item, dict)]

    if not isinstance(raw_payload, dict):
        return []

    candidates = [
        raw_payload.get("records"),
        raw_payload.get("orders"),
        raw_payload.get("items"),
        raw_payload.get("list"),
    ]

    data = raw_payload.get("data")
    if isinstance(data, dict):
        candidates.extend([
            data.get("records"),
            data.get("orders"),
            data.get("items"),
            data.get("list"),
        ])

    for candidate in candidates:
        if isinstance(candidate, list):
            return [item for item in candidate if isinstance(item, dict)]

    return []


def _build_row_payload(
    request_payload: dict,
    result: dict,
    raw_payload: Any,
    record: dict[str, Any] | None,
    index: int,
    total: int,
    dropped_count: int,
) -> dict[str, Any]:
    response_summary = {
        "success": result.get("success"),
        "accepted": result.get("accepted"),
        "final": result.get("final"),
        "code": result.get("code"),
        "message": result.get("message"),
        "external_id": result.get("external_id"),
    }
    raw_meta = raw_payload if isinstance(raw_payload, dict) else {"value": raw_payload}
    if isinstance(raw_meta, dict) and "records" in raw_meta:
        raw_meta = {key: value for key, value in raw_meta.items() if key != "records"}

    records = [record] if isinstance(record, dict) else []
    payload = {
        "request": request_payload,
        "response": response_summary,
        "raw_payload": {
            "records": records,
            "meta": raw_meta,
            "record_index": index,
            "record_count": total,
            "dropped_count": dropped_count,
        },
    }
    return _sanitize_payload(payload, settings.worker_max_row_payload_bytes, "raw_row_payload")


def _pick_record_id(
    record: dict[str, Any],
    keys: list[str],
    fallback_external_id: Any,
    index: int,
) -> str | None:
    for key in keys:
        value = record.get(key)
        if value is None:
            continue
        text = str(value).strip()
        if text != "":
            return text

    if fallback_external_id:
        text = str(fallback_external_id).strip()
        if text != "":
            return f"{text}:{index}"
    return None


def _load_existing_event_keys(session, model, sync_task_id: int) -> set[str]:
    stmt = select(model.event_key).where(
        model.sync_task_id == sync_task_id,
        model.event_key.is_not(None),
    )
    return {
        str(value).strip()
        for (value,) in session.execute(stmt).all()
        if value is not None and str(value).strip() != ""
    }


def _build_worker_failure_result(*, code: str, message: str, detail: dict[str, Any] | None = None) -> dict[str, Any]:
    return {
        "success": False,
        "accepted": False,
        "final": True,
        "code": code,
        "message": message,
        "external_id": "",
        "raw_payload": {
            "worker_guard": True,
            "detail": detail or {},
        },
    }


def _apply_result_or_fallback(task: SyncTask, result: dict[str, Any]) -> None:
    try:
        apply_execution_result(task, result)
    except InvalidStatusTransitionError as exc:
        task.status = SyncStatus.MANUAL_REVIEW.value
        task.last_error_code = "INVALID_TRANSITION"
        task.last_error_message = str(exc)
        task.finished_at = datetime.utcnow()


def _payload_size_bytes(payload: Any) -> int:
    try:
        encoded = json.dumps(payload, ensure_ascii=False, default=str).encode("utf-8")
    except (TypeError, ValueError):
        encoded = str(payload).encode("utf-8", errors="ignore")
    return len(encoded)


def _sanitize_payload(payload: Any, max_bytes: int, label: str) -> Any:
    payload_size = _payload_size_bytes(payload)
    if payload_size <= max_bytes:
        return payload

    keys: list[str] = []
    if isinstance(payload, dict):
        keys = [str(key) for key in list(payload.keys())[:20]]

    return {
        "truncated": True,
        "label": label,
        "original_size_bytes": payload_size,
        "max_bytes": max_bytes,
        "keys_preview": keys,
    }


@shared_task(name="app.tasks.sync_worker.execute_pending_sync_tasks")
def execute_pending_sync_tasks() -> dict:
    task_ids = _fetch_runnable_task_ids(settings.worker_batch_size)
    processed = 0
    for task_id in task_ids:
        try:
            _process_task(task_id)
            processed += 1
        except Exception as exc:  # noqa: BLE001
            logger.exception("Failed processing sync task %s: %s", task_id, exc)
    return {"picked": len(task_ids), "processed": processed}
