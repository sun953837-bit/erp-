from datetime import datetime, timezone

from fastapi import FastAPI

from app.tasks.sync_worker import execute_pending_sync_tasks
from app.scheduler.polling_jobs import poll_accepted_sync_tasks

app = FastAPI(title="Python Sync Service", version="0.1.0")


@app.get("/internal/health")
def health() -> dict:
    return {
        "success": True,
        "service": "python-sync",
        "time": datetime.now(timezone.utc).isoformat(),
    }


@app.post("/internal/trigger/worker")
def trigger_worker() -> dict:
    task = execute_pending_sync_tasks.delay()
    return {"success": True, "task_id": task.id}


@app.post("/internal/trigger/polling")
def trigger_polling() -> dict:
    task = poll_accepted_sync_tasks.delay()
    return {"success": True, "task_id": task.id}
