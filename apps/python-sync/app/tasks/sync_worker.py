from __future__ import annotations

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

        task_payload = task.payload_json or {}
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
            request_payload=request_payload,
            response_payload=None,
            response=None,
            http_status=None,
        )
        session.add(request_log)
        session.flush()

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

        response_log = create_receipt(
            sync_task_id=task.id,
            platform_code=task.platform_code,
            phase="RESPONSE",
            endpoint=endpoint,
            request_payload=adapter_payload,
            response_payload=result.get("raw_payload"),
            response=result,
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

        try:
            apply_execution_result(task, result)
        except InvalidStatusTransitionError as exc:
            task.status = SyncStatus.MANUAL_REVIEW.value
            task.last_error_code = "INVALID_TRANSITION"
            task.last_error_message = str(exc)
            task.finished_at = datetime.utcnow()

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
    records = _extract_pull_records(raw_payload)

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
) -> None:
    if not records:
        session.add(
            RawOrder(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_order_id=str(fallback_external_id) if fallback_external_id else None,
                payload_json=_build_row_payload(request_payload, result, raw_payload, None, 0, 0),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    total = len(records)
    for index, record in enumerate(records, start=1):
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
                event_key=f"{task.task_no}:{index}",
                external_order_id=external_order_id,
                payload_json=_build_row_payload(request_payload, result, raw_payload, record, index, total),
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
) -> None:
    if not records:
        session.add(
            RawRefund(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_refund_id=str(fallback_external_id) if fallback_external_id else None,
                payload_json=_build_row_payload(request_payload, result, raw_payload, None, 0, 0),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    total = len(records)
    for index, record in enumerate(records, start=1):
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
                event_key=f"{task.task_no}:{index}",
                external_refund_id=external_refund_id,
                payload_json=_build_row_payload(request_payload, result, raw_payload, record, index, total),
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
) -> None:
    if not records:
        session.add(
            RawService(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_service_id=str(fallback_external_id) if fallback_external_id else None,
                payload_json=_build_row_payload(request_payload, result, raw_payload, None, 0, 0),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    total = len(records)
    for index, record in enumerate(records, start=1):
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
                event_key=f"{task.task_no}:{index}",
                external_service_id=external_service_id,
                payload_json=_build_row_payload(request_payload, result, raw_payload, record, index, total),
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
) -> None:
    if not records:
        session.add(
            RawListing(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_listing_id=str(fallback_external_id) if fallback_external_id else None,
                payload_json=_build_row_payload(request_payload, result, raw_payload, None, 0, 0),
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    total = len(records)
    for index, record in enumerate(records, start=1):
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
                event_key=f"{task.task_no}:{index}",
                external_listing_id=external_listing_id,
                payload_json=_build_row_payload(request_payload, result, raw_payload, record, index, total),
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
    return {
        "request": request_payload,
        "response": response_summary,
        "raw_payload": {
            "records": records,
            "meta": raw_meta,
            "record_index": index,
            "record_count": total,
        },
    }


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
