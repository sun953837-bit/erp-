# Service Orders -> Orders Migration Design (P1.2)

## Goal

Converge legacy `service_orders` into canonical `orders` with `order_type=service`, while reserving `order_type=goods` baseline path.

## Target Schema

- Canonical table: `orders`
  - `order_type` in `service|goods`
  - `legacy_service_order_id` keeps back-reference during migration
  - dual-write linkage fields: `customer_id`, `project_id`, `ticket_id`
- Canonical item table: `order_items`
- Goods baseline fulfillment table: `goods_order_fulfillments`

## Phases

1. **Phase-A (current batch)**
- Create canonical tables.
- Open canonical write API `/api/orders`.
- Freeze legacy write API `/api/service-orders` (`FREEZE_LEGACY_SERVICE_ORDER_WRITE=true`).
- Keep legacy read path + channel mapping unchanged to avoid operational shock.

2. **Phase-B (dual write)**
- Channel mapping + service order domain writes to both `service_orders` and `orders(order_type=service)`.
- Compare row counts and key fields by daily reconciliation script.
  - Implemented:
    - dual-write service: `ServiceOrderDualWriteService`
    - service order baseline item auto-sync: `order_items(item_type=service)`
    - finance snapshot sync on canonical order: `meta_json.finance_snapshot`
    - reconciliation API: `GET /api/orders/reconciliation/service`
    - reconciliation CLI: `php artisan orders:service-reconcile --sample-limit=50 [--date-from=YYYY-MM-DD --date-to=YYYY-MM-DD --platform-code=xianyu --shop-id=1]`
    - scheduled reconciliation cron:
      - `SERVICE_ORDER_RECON_AUTO_ENABLED`
      - `SERVICE_ORDER_RECON_CRON`
      - `SERVICE_ORDER_RECON_SAMPLE_LIMIT`

3. **Phase-C (cutover)**
- Switch BI and business read path from `service_orders` to `orders` for service view.
- Keep `service_orders` as compatibility read-only snapshot.

4. **Phase-D (deprecation)**
- Remove legacy write and old aliases.
- Archive `service_orders` and migrate dependent FKs if needed.

## Backfill SQL Sketch

```sql
INSERT INTO orders (
  order_no, order_type, platform_code, shop_id, external_order_id,
  legacy_service_order_id, subject, customer_name, currency, amount,
  status, delivery_mode, meta_json, confirmed_at, completed_at, created_at, updated_at
)
SELECT
  order_no, 'service', platform_code, shop_id, external_order_id,
  id, service_name, customer_name, currency, amount,
  status, delivery_mode, meta_json, confirmed_at, completed_at, created_at, updated_at
FROM service_orders;
```

## Risk Controls

- Keep compatibility read path until dual-write verification is stable.
- Use `legacy_service_order_id` unique index to make backfill idempotent.
- Freeze new legacy API writes before dual-write starts.
