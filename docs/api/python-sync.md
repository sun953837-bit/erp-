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
- Advance state by state-machine rules.
- Do not write directly into ERP business tables for pull flows; raw ingest first, then mapped by PHP channel-hub mapping job.

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

## Xianyu Pull Orders Source Mode

`xianyu` adapter supports configurable source mode for `pull_orders`:

- `XIANYU_ORDERS_SOURCE_MODE=mock` (default): keep existing mock records behavior.
- `XIANYU_ORDERS_SOURCE_MODE=http`: fetch from external HTTP endpoint and normalize into Stage-1 raw format.

Related env vars:

- `XIANYU_ORDERS_ENDPOINT`
- `XIANYU_ACCESS_TOKEN` (optional, `Authorization: Bearer`)
- `XIANYU_APP_KEY` (optional, `X-App-Key`)
- `XIANYU_HTTP_TIMEOUT_SECONDS`
- `XIANYU_ORDERS_EXTRA_PARAMS_JSON` (optional JSON object merged into query params)

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
