# Kuajing CRM + ERP Middle Platform (V1.1 Scaffold)

## Overview
This repository is the first-round runnable scaffold for a cross-border e-commerce CRM + ERP middle platform.

- `apps/php-api`: Laravel API (business layer, task creation)
- `apps/python-sync`: FastAPI + Celery sync engine (adapter + state machine + worker)
- `packages/shared-contracts`: shared status/task/event contracts
- `packages/mock-data`: demo mock data

Core principle enforced in this version:
`ACCEPTED` is **not** `SUCCESS`.

## P0.5 Scope Lock (Current Working Rule)
Before adding new features, align docs/contracts first and freeze Stage-1 scope:

- Sync-first path only: `xianyu_adapter`, `zbj_adapter`, `channel_hub`, `order_center`, `delivery_center`, `finance_center`
- Do not expand to SKU/inventory/assets/advanced CRM in current batch
- Keep current implementation model: existing monolith + incremental closure

Details:

- Migration truth: `infra/migrations/README.md`
- API truth and boundaries: `docs/api/overview-phase-a.md`
- Stage-1 scope freeze: `docs/architecture/stage-1-scope-freeze.md`
- Naming governance: `docs/architecture/naming-governance.md`
- Skeleton vs implementation status: `docs/architecture/skeleton-vs-implementation.md`

## Quick Start
1. Copy environment config:
   ```bash
   cp .env.example .env
   ```
2. Build and start:
   ```bash
   docker compose up --build -d
   ```
3. Check services:
   - PHP API: `http://localhost:8000/api/health`
   - Python Sync API: `http://localhost:8100/internal/health`

## Demo Flow
1. Create sync task from PHP API (`product_publish`).
2. Celery worker pulls `PENDING/RETRYING` tasks and runs mock provider.
3. Worker writes `sync_receipt_logs` (`REQUEST`, `RESPONSE`).
4. Task enters `SUCCESS` or `ACCEPTED`.
5. Scheduler polls `ACCEPTED` tasks and finally settles to `SUCCESS`/`FAIL`/`MANUAL_REVIEW`.
6. Failed tasks can be retried through `/api/sync-tasks/{id}/retry`.

## Useful Commands
- Start dev stack: `./scripts/dev-up.sh`
- Start dev stack: `sh scripts/dev-up.sh`
- Run DB migrations manually:
  ```bash
  docker compose exec php-api php artisan migrate --force
  ```
- Seed demo data:
  ```bash
  sh scripts/seed-demo.sh
  ```

## Notes
- No real marketplace API is called in V1.
- Credentials are never returned in plaintext.
- SMS code plaintext is not stored.
