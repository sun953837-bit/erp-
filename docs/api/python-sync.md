# Python Sync API / Worker

Base URL: `http://localhost:8100`

## Internal API
- `GET /internal/health`

## Worker Responsibilities
- Scan `sync_tasks` where status in `PENDING`, `RETRYING` and retry time is due.
- Execute provider action by `task_type`.
- Write `sync_receipt_logs` for `REQUEST` and `RESPONSE`.
- For pull tasks, persist raw payload to `raw_orders` / `raw_refunds` / `raw_listings` / `raw_services`.
  - If payload contains `records[]`, worker now writes one `raw_*` row per record.
  - Each row payload keeps single-record shape (`raw_payload.records=[record]`) for deterministic mapping.
  - Pull action failures (`success=false`) do not write `raw_*` rows.
  - Per-task max record guard: `WORKER_MAX_PULL_RECORDS_PER_TASK` (default 200), overflow records are dropped with warning.
  - Payload size guard:
    - `WORKER_MAX_PAYLOAD_BYTES` protects request/response payloads and receipt logs.
    - `WORKER_MAX_ROW_PAYLOAD_BYTES` protects per-row raw payload persistence.
  - Raw idempotency guard: skip duplicate `event_key` rows for the same `sync_task_id`.
  - DB idempotency guard: unique index on `(sync_task_id, event_key)` for each `raw_*` table.
- Advance state by state-machine rules.
- Do not write directly into ERP business tables for pull flows; raw ingest first, then mapped by PHP channel-hub mapping job.
- When adapter result indicates auth expiration/revoke, worker writes back channel account auth status to PHP API endpoint:
  - `PATCH /api/channel-accounts/by-shop/{shopId}/auth-status`

## Polling Scheduler Responsibilities
- Scan `ACCEPTED` tasks.
- Call provider `query_result`.
- Write `POLLING` receipts.
- Finalize to `SUCCESS` or failure states.

## Mock Providers
- amazon_mock
- tiktok_mock
- japan_mock
- korea_mock
- xianyu_mock
- zbj_mock

## Xianyu/ZBJ Pull Source Mode (Unified HTTP Kernel)

`xianyu` and `zbj` adapters support configurable source mode for pull actions:

- `XIANYU_ORDERS_SOURCE_MODE=mock` (default): keep existing mock records behavior.
- `XIANYU_ORDERS_SOURCE_MODE=http`: fetch from external HTTP endpoint and normalize into Stage-1 raw format.
- `XIANYU_REFUNDS_SOURCE_MODE=mock|http`
- `XIANYU_LISTINGS_SOURCE_MODE=mock|http`
- `ZBJ_ORDERS_SOURCE_MODE=mock` (default): keep existing mock records behavior.
- `ZBJ_ORDERS_SOURCE_MODE=http`: fetch from external HTTP endpoint and normalize into Stage-1 raw format.
- `ZBJ_REFUNDS_SOURCE_MODE=mock|http`
- `ZBJ_SERVICES_SOURCE_MODE=mock|http`

Related env vars:

- `XIANYU_ORDERS_ENDPOINT`
- `XIANYU_ACCESS_TOKEN` (optional, `Authorization: Bearer`)
- `XIANYU_APP_KEY` (optional, `X-App-Key`)
- `XIANYU_HTTP_TIMEOUT_SECONDS`
- `XIANYU_HTTP_RETRY_ATTEMPTS`
- `XIANYU_HTTP_RETRY_BACKOFF_SECONDS`
- `XIANYU_HTTP_RATE_LIMIT_PER_SECOND`
- `XIANYU_ORDERS_EXTRA_PARAMS_JSON` (optional JSON object merged into query params)
- `XIANYU_REFUNDS_ENDPOINT`
- `XIANYU_REFUNDS_EXTRA_PARAMS_JSON`
- `XIANYU_LISTINGS_ENDPOINT`
- `XIANYU_LISTINGS_EXTRA_PARAMS_JSON`
- `ZBJ_ORDERS_ENDPOINT`
- `ZBJ_ACCESS_TOKEN` (optional, `Authorization: Bearer`)
- `ZBJ_APP_KEY` (optional, `X-App-Key`)
- `ZBJ_HTTP_TIMEOUT_SECONDS`
- `ZBJ_HTTP_RETRY_ATTEMPTS`
- `ZBJ_HTTP_RETRY_BACKOFF_SECONDS`
- `ZBJ_HTTP_RATE_LIMIT_PER_SECOND`
- `ZBJ_ORDERS_EXTRA_PARAMS_JSON` (optional JSON object merged into query params)
- `ZBJ_REFUNDS_ENDPOINT`
- `ZBJ_REFUNDS_EXTRA_PARAMS_JSON`
- `ZBJ_SERVICES_ENDPOINT`
- `ZBJ_SERVICES_EXTRA_PARAMS_JSON`

Unified pull protocol additions:
- Pagination request fields:
  - `page`, `page_size`, `cursor`, or nested `pagination={...}`
- Incremental request fields:
  - `since`, `last_pulled_at`, `incremental.cursor`, `incremental.incremental_cursor`
- Response metadata:
  - `raw_payload.meta.pagination`
  - `raw_payload.meta.incremental`
  - `raw_payload.meta.retry_attempts`

Standard adapter error codes:
- `PULL_SOURCE_CONFIG_ERROR`
- `PULL_HTTP_REQUEST_FAILED`
- `PULL_HTTP_INVALID_RESPONSE`
- `PULL_HTTP_RATE_LIMITED`
- `PULL_HTTP_RETRY_EXHAUSTED`

Channel-account auth writeback env vars:

- `CHANNEL_ACCOUNT_SYNC_ENABLED`
- `CHANNEL_ACCOUNT_SYNC_TIMEOUT_SECONDS`
- `CHANNEL_ACCOUNT_SYNC_INTERNAL_TOKEN` (optional header passthrough)
- `PHP_API_BASE_URL`

`payload.mock_mode` options:
- `success_immediate`
- `accepted_then_success`
- `accepted_then_fail`
- `fail_immediate`

## Adapter Capabilities (Current)

- `create_product`
- `update_product`
- `sync_inventory`
- `pull_orders`
- `pull_refunds`
- `pull_listings`
- `pull_services`
- `query_result`
