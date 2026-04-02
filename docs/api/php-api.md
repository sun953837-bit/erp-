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
  - maps pending `raw_orders`:
    - `order_type=service` -> `service_orders` (+ dual-write canonical `orders`)
    - `order_type=goods` -> canonical `orders(order_type=goods)` + `order_items` + baseline `goods_order_fulfillments`
  - maps pending `raw_refunds` -> `refund_records` + `reconciliation_records`
  - maps pending `raw_listings(order_type/listing_type=goods)` -> `platform_product_mappings`
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

`POST /service-orders` is now a frozen legacy write path when `FREEZE_LEGACY_SERVICE_ORDER_WRITE=true`:
- returns `409 LEGACY_WRITE_FROZEN`
- canonical write path is `POST /orders`

## Orders (Canonical)
- `GET /orders`
- `POST /orders`
- `GET /orders/{id}`
- `PATCH /orders/{id}/status`
- `GET /orders/goods`
- `POST /orders/goods`
- `GET /orders/reconciliation/service`
- `GET /orders/goods/fulfillments`
- `PATCH /orders/goods/fulfillments/{id}/status`
- `POST /orders/goods/fulfillments/{id}/writeback`
- `POST /orders/goods/fulfillments/{id}/push-shipment`
- `GET /orders/goods/after-sales`
- `POST /orders/goods/after-sales`
- `PATCH /orders/goods/after-sales/{id}/status`

Canonical rules:
- `order_type` uses `service|goods`
- `POST /orders` rejects frozen legacy alias fields (`service_order_id`, `goods_order_id`, etc.)
- goods baseline uses `order_items` + `goods_order_fulfillments` only (no inventory extension in Stage-1)
- service dual-write:
  - legacy `service_orders` changes are mirrored to canonical `orders(order_type=service)`
  - canonical service writes mirror back to `service_orders`
  - reconciliation API/CLI:
    - `GET /api/orders/reconciliation/service`
    - `php artisan orders:service-reconcile --sample-limit=50`
    - reconciliation filters:
      - API query: `date_from`, `date_to`, `platform_code`, `shop_id|account_id`
      - CLI options: `--date-from`, `--date-to`, `--platform-code`, `--shop-id`
  - service canonical schema carries migration-phase linkage fields:
    - `customer_id`
    - `project_id`
    - `ticket_id`
  - finance write path updates canonical order `meta_json.finance_snapshot` for payment/refund/reconciliation linkage check

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
- `X-Timestamp`: required when `WEBHOOK_REQUIRE_TIMESTAMP=true`

Behavior:

- signature mismatch -> `401`
- timestamp out of allowed window (`WEBHOOK_ALLOWED_DRIFT_SECONDS`) -> `401`
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
- `GET /bi/etl/monitor`
- `POST /bi/etl/refresh`
  - supported mode: `full|incremental|stage1`
  - `stage1` strategy:
    - default incremental
    - auto-upgrade to full when `last_success_at` lag exceeds `BI_ETL_STAGE1_FULL_LAG_HOURS`
- `POST /bi/etl/recover`
  - failure compensation entrypoint
  - invokes configured recover mode (`BI_ETL_FAILURE_RECOVER_MODE`)
  - Supported mode:
    - full refresh (`mode=full`)
    - incremental refresh (`mode=incremental`, `window_days=1..90`)
  - Writes Stage-1 theme tables:
    - `dim_platform`, `dim_shop`, `dim_customer`, `dim_service`, `dim_product`, `dim_date`
    - `fact_service_orders`, `fact_goods_orders`, `fact_after_sales`, `fact_settlements`, `fact_project_delivery`
  - Service read source can switch with fallback:
    - `READ_SERVICE_FROM_CANONICAL_ORDERS`
    - `READ_SERVICE_FROM_CANONICAL_ORDERS_FALLBACK`
  - monitor/summary/refresh payload includes `service_source_comparison` to compare legacy/canonical service read volumes and amount
  - ETL source/target connection can be split:
    - `BI_ETL_SOURCE_CONNECTION`
    - `BI_ETL_TARGET_CONNECTION`
  - `fact_after_sales` includes two sources:
    - `refund_record` (from `refund_records`)
    - `service_order_status` (orders in `after_sale` state without refund rows)

CLI + schedule:
- `php artisan bi:etl-refresh --mode=full`
- `php artisan bi:etl-refresh --mode=incremental --window-days=3`
- `php artisan bi:etl-refresh --mode=stage1 --window-days=3`
- `php artisan bi:etl-recover`
- scheduled incremental refresh is controlled by `.env`:
  - `BI_ETL_AUTO_REFRESH_ENABLED`
  - `BI_ETL_CRON`
  - `BI_ETL_INCREMENTAL_WINDOW_DAYS`
  - `BI_ETL_STAGE1_FULL_LAG_HOURS`
  - `BI_ETL_AUTO_RECOVER_ENABLED`
  - `BI_ETL_RECOVER_CRON`
  - `BI_ETL_FAILURE_RECOVER_MODE`
  - `BI_ETL_ALERT_ENABLED`
  - `BI_ETL_ALERT_PRIORITY`
  - `READ_SERVICE_FROM_CANONICAL_ORDERS`
  - `READ_SERVICE_FROM_CANONICAL_ORDERS_FALLBACK`
  - `BI_ETL_SOURCE_CONNECTION`
  - `BI_ETL_TARGET_CONNECTION`
  - `BI_READONLY_SCHEMA`
  - `BI_READONLY_USERNAME`

Raw mapping CLI + schedule:
- `php artisan channel:map-raw --limit=100`
- scheduled raw mapping is controlled by `.env`:
  - `RAW_MAPPING_AUTO_ENABLED`
  - `RAW_MAPPING_CRON`
  - `RAW_MAPPING_LIMIT`

Dual-write repair CLI:
- `php artisan orders:service-backfill-canonical`
- optional one-off limit: `php artisan orders:service-backfill-canonical --limit=200`

## Legacy Contract Notes

- `PATCH /orders/{id}/status` is implemented in canonical order path.
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
