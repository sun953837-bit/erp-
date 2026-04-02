# Python Sync API / Worker

Base URL: `http://localhost:8100`

## Internal API
- `GET /internal/health`

## Worker Responsibilities
- Scan `sync_tasks` where status in `PENDING`, `RETRYING` and retry time is due.
- Execute provider action by `task_type`.
- Write `sync_receipt_logs` for `REQUEST` and `RESPONSE`.
- For pull tasks, persist raw payload to `raw_orders` / `raw_refunds` / `raw_listings` / `raw_services`.
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
