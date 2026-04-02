# Skeleton vs Implementation (P0.5)

## Directory Status

### Implemented (usable in current scaffold)

- `apps/php-api`
  - Laravel routes/controllers/models/migrations are executable.
  - Sync task creation/list/retry and receipt query are available.
  - Manual run entry and webhook intake (signature + idempotency + failure log) are available.
  - Raw channel mapping for orders/refunds is available through API/CLI scheduler.
  - Raw mapping logic is split into parser/status-mapper/domain services to keep channel-hub logic maintainable.
  - Minimal service-order and finance linkage is available (status machine + receivable/payment/refund/reconciliation APIs).
- `apps/python-sync`
  - Worker/scheduler framework is present.
  - Provider adapters are mock implementations (`xianyu`, `zbj` included).
  - `xianyu` supports HTTP source mode for `pull_orders/pull_refunds/pull_listings`.
  - `zbj` supports HTTP source mode for `pull_orders/pull_refunds/pull_services`.
  - Pull-task raw persistence writes into `raw_orders` / `raw_refunds` / `raw_listings` / `raw_services`.
  - Pull-task raw ingest now persists one `raw_*` row per `records[]` item when batch payload is returned.
  - Worker can write back channel-account auth status (`EXPIRED`/`REVOKED`) to PHP API when adapter returns auth-failure signals.
- `docs/api`, `docs/architecture`
  - Documentation base exists and can be aligned with runtime truth.

### Skeleton / Partial

- `apps/python-sync/app/adapters/*`
  - Mock providers exist; no real platform integration yet.
- Stage-1 domain modules (`channel_hub`, `order_center`, `delivery_center`, `finance_center`)
  - Not separated as concrete modules in filesystem yet.
- BI preparation
  - Minimal Stage-1 BI ETL is implemented via:
    - API: `POST /api/bi/etl/refresh`, `GET /api/bi/etl/summary`
    - CLI: `php artisan bi:etl-refresh --mode=full|incremental`
    - Scheduler: env-driven incremental refresh (`BI_ETL_*`)
  - Scope is limited to theme-table refresh and run metadata; no dashboard/report-layer implementation.

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
