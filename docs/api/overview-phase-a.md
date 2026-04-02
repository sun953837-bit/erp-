# API Overview (P0.5 Contract Baseline)

This document aligns API docs with the actual implementation for the current Stage-1 closure.

## Base URLs

- PHP API: `http://localhost:8000/api`
- Python Sync internal API: `http://localhost:8100/internal`

## Implemented PHP API Endpoints

### Health

- `GET /health`

### Auth

- `POST /auth/send-sms-code`
- `POST /auth/verify-sms`
- `POST /auth/login`

### Shops

- `GET /shops`
- `POST /shops`
- `PUT /shops/{id}`

### Channel Accounts

- `GET /channel-accounts`
- `PATCH /channel-accounts/{id}/auth-status`
- `PATCH /channel-accounts/by-shop/{shopId}/auth-status`

### Channel Hub / Raw Mapping

- `GET /channel-hub/raw-mapping/summary`
- `POST /channel-hub/raw-mapping/run`
  - Optional `limit` (default `100`, max `1000`)
  - Maps `raw_orders(PENDING)` into `service_orders`
  - Maps `raw_refunds(PENDING)` into `refund_records` / `reconciliation_records`
  - Raw rows are marked as `MAPPED`, `SKIPPED`, or `FAILED`

### Products

- `GET /products/spu`
- `POST /products/spu`
- `GET /products/sku`
- `POST /products/sku`

### Service Orders

- `GET /service-orders`
- `POST /service-orders`
- `GET /service-orders/{id}`
- `PATCH /service-orders/{id}/status`

### Platform Mapping

- `GET /platform-product-mappings`
- `POST /platform-product-mappings`

### Sync Tasks

- `POST /sync-tasks`
- `GET /sync-tasks`
- `GET /sync-tasks/{id}`
- `POST /sync-tasks/{id}/run`
- `POST /sync-tasks/{id}/retry`
- `GET /sync-tasks/{id}/receipts`
  - manual run endpoint attempts worker dispatch via python-sync internal trigger URL
  - dispatch failure mode is configurable by `SYNC_MANUAL_RUN_DISPATCH_FAILURE_MODE`:
    - `mark_manual_review` (default): return `503`, task moves to `MANUAL_REVIEW`
    - `keep_queued`: return `202`, task keeps queued state and stores `last_error_*`
  - manual run dispatch result is persisted into `audit_logs`

### Webhooks

- `GET /webhooks/events`
- `POST /webhooks/events/{id}/retry`
- `POST /webhooks/{platform}/events`
  - Signature validation enabled (`X-Signature`, HMAC-SHA256)
  - Idempotency enabled (`X-Event-Id` or payload key fallback)
  - Failed processing is persisted for retry callbacks

### Finance

- `GET /finance/receivables`
- `GET /finance/payments`
- `POST /finance/payments`
- `GET /finance/refunds`
- `POST /finance/refunds`
- `GET /finance/reconciliations`

### BI ETL (Stage-1 Minimal)

- `GET /bi/etl/summary`
- `POST /bi/etl/refresh`
  - Full refresh: `mode=full`
  - Incremental refresh: `mode=incremental`, `window_days=1..90`
  - Refresh scope for Stage-1 theme tables:
    - `dim_platform`, `dim_shop`, `dim_customer`, `dim_service`, `dim_date`
    - `fact_service_orders`, `fact_after_sales`, `fact_settlements`, `fact_project_delivery`
  - ETL run status is tracked in `bi_etl_runs`
  - `fact_after_sales` source policy:
    - `refund_record`: rows from `refund_records`
    - `service_order_status`: fallback rows for `service_orders.status=after_sale` without refund records

## Implemented Python Sync Endpoint

- `GET /internal/health`

## Contract Decisions (Conflict Resolution)

### Order status update API

- Legacy generic route `PATCH /orders/{id}/status` is not implemented.
- Service-order status update is implemented as `PATCH /service-orders/{id}/status`.
- `PUT /orders/{id}/status` is not documented as active in Stage-1.

### `finance/receivables`

- Current status: implemented as `GET /finance/receivables`.
- Receivable auto-creation is triggered on service-order confirmation.

## Stage-1 Boundary

- Current APIs focus on sync scaffolding and product/shop mapping flow.
- A minimal P1 subset is implemented: service-order status flow, auto receivable, payment/refund/reconciliation linkage.
- BI is limited to minimal theme-table refresh only. No dashboards/report-design scope is included in current batch.
