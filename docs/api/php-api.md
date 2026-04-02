# PHP API (Laravel) - V1 Endpoints

Base URL: `http://localhost:8000/api`

Contract baseline for P0.5 is maintained in `docs/api/overview-phase-a.md`.

## Health
- `GET /health`

## Auth
- `POST /auth/send-sms-code`
- `POST /auth/verify-sms`
- `POST /auth/login`

## Shops
- `GET /shops`
- `POST /shops`
- `PUT /shops/{id}`

## Channel Accounts
- `GET /channel-accounts`
- `PATCH /channel-accounts/{id}/auth-status`
- `PATCH /channel-accounts/by-shop/{shopId}/auth-status`

## Channel Hub / Raw Mapping
- `GET /channel-hub/raw-mapping/summary`
- `POST /channel-hub/raw-mapping/run`
  - optional payload: `{"limit": 100}`
  - maps pending `raw_orders` -> `service_orders`
  - maps pending `raw_refunds` -> `refund_records` + `reconciliation_records`
  - updates `raw_orders.mapped_status` / `raw_refunds.mapped_status` (`MAPPED|SKIPPED|FAILED`)

## Products
- `GET /products/spu`
- `POST /products/spu`
- `GET /products/sku`
- `POST /products/sku`

## Service Orders
- `GET /service-orders`
- `POST /service-orders`
- `GET /service-orders/{id}`
- `PATCH /service-orders/{id}/status`

## Platform Mapping
- `GET /platform-product-mappings`
- `POST /platform-product-mappings`

## Sync Tasks
- `POST /sync-tasks`
- `GET /sync-tasks`
- `GET /sync-tasks/{id}`
- `POST /sync-tasks/{id}/run`
- `POST /sync-tasks/{id}/retry`
- `GET /sync-tasks/{id}/receipts`

`POST /sync-tasks/{id}/run` behavior:
- marks task for immediate execution (`next_retry_at=now`)
- attempts to trigger python-sync worker once via internal URL (`PYTHON_SYNC_INTERNAL_URL`)
- always returns both task snapshot and dispatch result
- dispatch failure handling is controlled by `SYNC_MANUAL_RUN_DISPATCH_FAILURE_MODE`:
  - `mark_manual_review` (default): task is moved to `MANUAL_REVIEW`, returns `503 SYNC_WORKER_UNAVAILABLE`
  - `keep_queued`: task remains queued with `last_error_*` updated, returns `202 PARTIAL_OK`
- each manual run attempt writes audit log row (`sync_task_manual_run_dispatched` / `sync_task_manual_run_dispatch_failed`)

`task_type` currently supported:

- `product_publish`
- `product_update`
- `inventory_sync`
- `order_pull`
- `refund_pull`
- `listing_pull`
- `service_pull`

## Webhooks

- `GET /webhooks/events`
- `POST /webhooks/events/{id}/retry`
- `POST /webhooks/{platform}/events`

Required headers:

- `X-Signature`: `hash_hmac('sha256', raw_body, WEBHOOK_SECRET_<PLATFORM> | WEBHOOK_SHARED_SECRET)`
- `X-Event-Id`: optional but recommended for stable idempotency key

Behavior:

- signature mismatch -> `401`
- duplicate processed event -> success with deduplicated result
- failed processing -> `500`, event status stored as `FAILED` and can be retried by provider callback
- failed events can be queried via `GET /webhooks/events?status=FAILED` and retried with `POST /webhooks/events/{id}/retry`

## Finance
- `GET /finance/receivables`
- `GET /finance/payments`
- `POST /finance/payments`
- `GET /finance/refunds`
- `POST /finance/refunds`
- `GET /finance/reconciliations`

## BI ETL (Stage-1 Minimal)
- `GET /bi/etl/summary`
- `POST /bi/etl/refresh`
  - Supported mode:
    - full refresh (`mode=full`)
    - incremental refresh (`mode=incremental`, `window_days=1..90`)
  - Writes Stage-1 theme tables:
    - `dim_platform`, `dim_shop`, `dim_customer`, `dim_service`, `dim_date`
    - `fact_service_orders`, `fact_after_sales`, `fact_settlements`, `fact_project_delivery`
  - `fact_after_sales` includes two sources:
    - `refund_record` (from `refund_records`)
    - `service_order_status` (orders in `after_sale` state without refund rows)

CLI + schedule:
- `php artisan bi:etl-refresh --mode=full`
- `php artisan bi:etl-refresh --mode=incremental --window-days=3`
- scheduled incremental refresh is controlled by `.env`:
  - `BI_ETL_AUTO_REFRESH_ENABLED`
  - `BI_ETL_CRON`
  - `BI_ETL_INCREMENTAL_WINDOW_DAYS`

Raw mapping CLI + schedule:
- `php artisan channel:map-raw --limit=100`
- scheduled raw mapping is controlled by `.env`:
  - `RAW_MAPPING_AUTO_ENABLED`
  - `RAW_MAPPING_CRON`
  - `RAW_MAPPING_LIMIT`

## Legacy Contract Notes

- `PATCH /orders/{id}/status` (legacy generic order route) is still not implemented.
- `PUT /orders/{id}/status` is not an active contract.

## Unified Response
```json
{
  "success": true,
  "code": "OK",
  "message": "success",
  "data": {}
}
```

Error example:
```json
{
  "success": false,
  "code": "VALIDATION_ERROR",
  "message": "The given data was invalid.",
  "data": null
}
```
