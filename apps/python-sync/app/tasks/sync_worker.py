from __future__ import annotations

import logging
from datetime import datetime

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

    now = datetime.utcnow()
    raw_payload = result.get("raw_payload")
    merged_payload = {
        "request": request_payload,
        "response": result,
        "raw_payload": raw_payload if isinstance(raw_payload, dict) else {"value": raw_payload},
    }
    external_id = result.get("external_id")

    if endpoint == "pull_orders":
        session.add(
            RawOrder(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_order_id=str(external_id) if external_id else None,
                payload_json=merged_payload,
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    if endpoint == "pull_refunds":
        session.add(
            RawRefund(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_refund_id=str(external_id) if external_id else None,
                payload_json=merged_payload,
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    if endpoint == "pull_services":
        session.add(
            RawService(
                sync_task_id=task.id,
                platform_code=task.platform_code,
                shop_id=task.shop_id,
                site_code=task.site_code,
                event_key=task.task_no,
                external_service_id=str(external_id) if external_id else None,
                payload_json=merged_payload,
                mapped_status="PENDING",
                received_at=now,
                created_at=now,
                updated_at=now,
            )
        )
        return

    session.add(
        RawListing(
            sync_task_id=task.id,
            platform_code=task.platform_code,
            shop_id=task.shop_id,
            site_code=task.site_code,
            event_key=task.task_no,
            external_listing_id=str(external_id) if external_id else None,
            payload_json=merged_payload,
            mapped_status="PENDING",
            received_at=now,
            created_at=now,
            updated_at=now,
        )
    )


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
