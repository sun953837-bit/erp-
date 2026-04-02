# Skeleton vs Implementation (P0.5)

## Directory Status

### Implemented (usable in current scaffold)

- `apps/php-api`
  - Laravel routes/controllers/models/migrations are executable.
  - Sync task creation/list/retry and receipt query are available.
  - Manual run entry and webhook intake (signature + idempotency + failure log) are available.
- `apps/python-sync`
  - Worker/scheduler framework is present.
  - Provider adapters are mock implementations (`xianyu`, `zbj` included).
  - Pull-task raw persistence writes into `raw_orders` / `raw_refunds` / `raw_listings`.
- `docs/api`, `docs/architecture`
  - Documentation base exists and can be aligned with runtime truth.

### Skeleton / Partial

- `apps/python-sync/app/adapters/*`
  - Mock providers exist; no real platform integration yet.
- Stage-1 domain modules (`channel_hub`, `order_center`, `delivery_center`, `finance_center`)
  - Not separated as concrete modules in filesystem yet.
- BI preparation
  - No Stage-1 BI dimension/fact ETL implementation in this repository.

## Repository Hygiene Suggestions

### Ignore/clean targets

- `**/node_modules/`
- `**/.venv/`
- `**/*.db`, `**/*.sqlite`, `**/*.sqlite3`
- `**/*.log`, `**/logs/`

### Why

- Prevent large dependency/noise files from polluting commits.
- Reduce accidental environment-specific artifacts in code review.

## Action Taken in This Batch

- Updated `codex53_tmp/.gitignore` to include the ignore set above.
- Added migration/API/scope/naming truth docs to reduce doc-implementation drift.
