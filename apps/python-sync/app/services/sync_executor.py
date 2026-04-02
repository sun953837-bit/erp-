from __future__ import annotations

from datetime import datetime
from uuid import uuid4

from app.adapters.factory import adapter_factory
from app.models.sync_receipt_log import SyncReceiptLog
from app.models.sync_task import SyncTask
from app.services.sync_state_machine import SyncStateMachine, SyncStatus


def create_receipt(
    *,
    sync_task_id: int,
    platform_code: str,
    phase: str,
    endpoint: str,
    request_payload: dict | None = None,
    response_payload: dict | None = None,
    response: dict | None = None,
    http_status: int | None = None,
) -> SyncReceiptLog:
    response_data = response or {}
    return SyncReceiptLog(
        sync_task_id=sync_task_id,
        request_id=str(uuid4()),
        phase=phase,
        http_status=http_status,
        platform_code=platform_code,
        endpoint=endpoint,
        success=response_data.get("success"),
        accepted=response_data.get("accepted"),
        final_result=response_data.get("final"),
        external_id=response_data.get("external_id"),
        code=response_data.get("code"),
        message=response_data.get("message"),
        request_payload=request_payload,
        response_payload=response_payload,
        created_at=datetime.utcnow(),
    )


def execute_task_action(task: SyncTask, adapter_payload: dict) -> tuple[str, dict]:
    adapter = adapter_factory.get(task.platform_code)

    if task.task_type == "product_publish":
        return "create_product", adapter.create_product(adapter_payload)
    if task.task_type == "product_update":
        return "update_product", adapter.update_product(adapter_payload)
    if task.task_type == "inventory_sync":
        return "sync_inventory", adapter.sync_inventory(adapter_payload)
    if task.task_type == "order_pull":
        return "pull_orders", adapter.pull_orders(adapter_payload)
    if task.task_type == "refund_pull":
        return "pull_refunds", adapter.pull_refunds(adapter_payload)
    if task.task_type == "listing_pull":
        return "pull_listings", adapter.pull_listings(adapter_payload)

    return (
        "unsupported",
        {
            "success": False,
            "accepted": False,
            "final": True,
            "code": "UNSUPPORTED_TASK_TYPE",
            "message": f"unsupported task type: {task.task_type}",
            "external_id": "",
            "raw_payload": {"task_type": task.task_type},
        },
    )


def apply_execution_result(task: SyncTask, result: dict) -> None:
    task.result_summary_json = result
    task.last_error_code = None
    task.last_error_message = None

    success = bool(result.get("success"))
    accepted = bool(result.get("accepted"))
    final = bool(result.get("final"))

    if success and not accepted and final:
        SyncStateMachine.validate_transition(task.status, SyncStatus.SUCCESS.value)
        task.status = SyncStatus.SUCCESS.value
        task.finished_at = datetime.utcnow()
        return

    if accepted and not final:
        SyncStateMachine.validate_transition(task.status, SyncStatus.ACCEPTED.value)
        task.status = SyncStatus.ACCEPTED.value
        task.accepted_at = datetime.utcnow()
        return

    task.retry_count = (task.retry_count or 0) + 1
    fail_target = SyncStateMachine.resolve_failure_target(task.retry_count, task.max_retry_count)
    SyncStateMachine.validate_transition(task.status, fail_target.value)
    task.status = fail_target.value
    task.last_error_code = result.get("code")
    task.last_error_message = result.get("message")
    task.finished_at = datetime.utcnow()
