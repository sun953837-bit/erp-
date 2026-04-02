# Raw Mapping Rules (Stage-1)

This document defines how channel raw data is mapped into ERP core tables in the current Stage-1 scope.

## Scope

- Implemented:
  - `raw_orders(order_type=service)` -> `service_orders` (+ dual-write to canonical `orders`)
  - `raw_orders(order_type=goods)` -> `orders(order_type=goods)` + `order_items` + `goods_order_fulfillments`
  - `raw_refunds` -> `refund_records` + `reconciliation_records`
  - `raw_listings(listing_type/order_type=goods)` -> `platform_product_mappings`
- Planned:
  - `raw_services` -> `services`

## Processing Policy

- Pull data must land in `raw_*` first.
- Python worker ingests pull payload as one raw row per record when provider returns `records[]`.
- Mapping reads only `mapped_status = PENDING` rows.
- Mapping writes back status:
  - `MAPPED`
  - `SKIPPED`
  - `FAILED`
- Mapping sets `processed_at` when a row leaves `PENDING`.

## Order Mapping

Source:
- `raw_payload.records[]` (fallback: `response.raw_payload.records[]`, `records[]`)

Selection:
- `order_type = service`: maps into legacy service domain, then dual-write into canonical order domain.
- `order_type = goods`: maps directly into canonical goods order domain.

Identity:
- `service_orders` unique lookup key:
  - `platform_code + shop_id + external_order_id`

Status mapping:
- `confirmed|paid|active` -> `confirmed`
- `in_delivery|delivering|processing` -> `in_delivery`
- `completed|done|finished|success` -> `completed`
- `after_sale|refunding` -> `after_sale`
- `closed|cancelled|canceled` -> `closed`
- others -> `pending`

Domain side effects:
- When order reaches `confirmed` or above:
  - ensure one `receivable_records`
  - ensure one delivery object (`project` or `ticket`)
- dual-write enabled:
  - `service_orders` -> `orders(order_type=service)` sync on each mapping pass

## Goods Order Mapping

Identity:
- `orders(order_type=goods)` lookup key:
  - `platform_code + shop_id + external_order_id`

Status normalization:
- `confirmed|paid|accepted` -> `confirmed`
- `shipped|in_delivery|delivering` -> `shipped`
- `completed|done|received` -> `completed`
- `after_sale|refunding|refunded` -> `after_sale`
- `closed` -> `closed`
- `cancelled|canceled` -> `cancelled`
- others -> `pending`

Domain side effects:
- ensure baseline `order_items` row(s) exists.
- ensure baseline `goods_order_fulfillments` exists for confirmed/shipped/completed states.

## Goods Listing Mapping

Selection:
- only listing records that represent goods listing type are mapped.

Target:
- upsert `platform_product_mappings` with:
  - `shop_id`, `platform_code`, `site_code`
  - `external_listing_id`, `external_sku_id`, `external_status`
  - mapped internal `sku_id`/`spu_id` by `sku_code`

## Refund Mapping

Source:
- `raw_payload.records[]` (fallback same as order mapping)

Identity:
- `refund_records` lookup:
  - primary: `service_order_id + platform_code + external_refund_id`
  - fallback (no external id): `service_order_id + platform_code + amount + reason`

Status mapping:
- `approved|pass` -> `APPROVED`
- `paid|finished|success|completed` -> `PAID`
- `rejected|reject|failed` -> `REJECTED`
- others -> `PENDING`

Domain side effects:
- Effective refund statuses (`APPROVED|PAID`) update receivable received amount.
- Reconciliation row is upserted by `refund_record_id`.
- Service order is promoted to `after_sale` when an effective refund appears.

## Manual Trigger

- API:
  - `POST /api/channel-hub/raw-mapping/run`
  - `GET /api/channel-hub/raw-mapping/summary`
- CLI:
  - `php artisan channel:map-raw --limit=100`
