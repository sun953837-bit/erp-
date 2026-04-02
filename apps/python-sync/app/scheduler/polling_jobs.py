from __future__ import annotations

import logging
from datetime import datetime

from celery import shared_task
from sqlalchemy import select

from app.adapters.factory import adapter_factory
from app.core.config import settings
from app.core.database import SessionLocal
from app.models.sync_task import SyncTask
from app.services.sync_executor import create_receipt
from app.services.sync_state_machine import (
    InvalidStatusTransitionError,
    SyncStateMachine,
    SyncStatus,
)

logger = logging.getLogger(__name__)


def _fetch_accepted_task_ids(batch_size: int) -> list[int]:
    with SessionLocal() as session:
        stmt = (
            select(SyncTask.id)
            .where(SyncTask.status == SyncStatus.ACCEPTED.value)
            .order_by(SyncTask.id.asc())
            .limit(batch_size)
        )
        return [row[0] for row in session.execute(stmt).all()]


def _poll_single_task(task_id: int) -> None:
    with SessionLocal() as session:
        task = session.get(SyncTask, task_id)
        if task is None or task.status != SyncStatus.ACCEPTED.value:
            return

        adapter = adapter_factory.get(task.platform_code)
        payload = dict(task.payload_json or {})
        summary = task.result_summary_json or {}
        payload["external_id"] = summary.get("external_id")
        payload["biz_id"] = task.biz_id
        payload["task_no"] = task.task_no

        result = adapter.query_result(payload)

        log = create_receipt(
            sync_task_id=task.id,
            platform_code=task.platform_code,
            phase="POLLING",
            endpoint="query_result",
            request_payload=payload,
            response_payload=result.get("raw_payload"),
            response=result,
            http_status=200,
        )
        session.add(log)

        try:
            if result.get("accepted") and not result.get("final"):
                task.updated_at = datetime.utcnow()
                session.commit()
                return

            if result.get("success") and result.get("final"):
                SyncStateMachine.validate_transition(task.status, SyncStatus.SUCCESS.value)
                task.status = SyncStatus.SUCCESS.value
                task.finished_at = datetime.utcnow()
                task.last_error_code = None
                task.last_error_message = None
            else:
                task.retry_count = (task.retry_count or 0) + 1
                fail_target = SyncStateMachine.resolve_failure_target(
                    task.retry_count, task.max_retry_count
                )
                SyncStateMachine.validate_transition(task.status, fail_target.value)
                task.status = fail_target.value
                task.last_error_code = result.get("code")
                task.last_error_message = result.get("message")
                task.finished_at = datetime.utcnow()

            task.result_summary_json = result
        except InvalidStatusTransitionError as exc:
            task.status = SyncStatus.MANUAL_REVIEW.value
            task.last_error_code = "INVALID_TRANSITION"
            task.last_error_message = str(exc)
            task.finished_at = datetime.utcnow()

        task.updated_at = datetime.utcnow()
        session.commit()


@shared_task(name="app.scheduler.polling_jobs.poll_accepted_sync_tasks")
def poll_accepted_sync_tasks() -> dict:
    task_ids = _fetch_accepted_task_ids(settings.worker_batch_size)
    processed = 0
    for task_id in task_ids:
        try:
            _poll_single_task(task_id)
            processed += 1
        except Exception as exc:  # noqa: BLE001
            logger.exception("Polling failed for sync task %s: %s", task_id, exc)
    return {"picked": len(task_ids), "processed": processed}
