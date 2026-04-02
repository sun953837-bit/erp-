# System Overview

## Scope
V1.1 focuses on a trusted sync middle platform skeleton for cross-border CRM + ERP.

## Components
- PHP API (Laravel): business APIs, master data, sync task creation, retry API.
- Python Sync (FastAPI + Celery): adapter abstraction, worker execution, polling confirmation.
- MySQL: single source of persistence (`sync_tasks`, `sync_receipt_logs`, product/shop tables).
- Redis: queue and cache for sync engine.

## Boundaries
- PHP API does not call platform adapters directly.
- Python sync service does not define migrations; it follows Laravel schema.
- Providers keep mock fallback, and `xianyu` / `zbj` support HTTP source mode with unified pull protocol.

## Reliability Rules
- Never treat local submit as platform final success.
- Persist request/response/polling receipts for every sync attempt.
- Idempotency is enforced by `idempotency_key` unique constraint.
- Pull ingestion adds worker-side protection:
  - per-task max pull record guard
  - payload size protection
  - raw event-key idempotent persistence check
- Raw tables enforce idempotency at DB level with unique `(sync_task_id, event_key)` constraints.
