from celery import Celery

from app.core.config import settings

celery_app = Celery(
    "python-sync",
    broker=settings.redis_url,
    backend=settings.redis_url,
    include=["app.tasks.sync_worker", "app.scheduler.polling_jobs"],
)

celery_app.conf.update(
    task_default_queue="sync_tasks",
    timezone="UTC",
    beat_schedule={
        "execute-pending-sync-tasks-every-15s": {
            "task": "app.tasks.sync_worker.execute_pending_sync_tasks",
            "schedule": 15.0,
        },
        "poll-accepted-sync-tasks-every-20s": {
            "task": "app.scheduler.polling_jobs.poll_accepted_sync_tasks",
            "schedule": 20.0,
        },
    },
)
