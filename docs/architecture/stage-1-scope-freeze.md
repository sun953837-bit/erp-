# Stage-1 Scope Freeze (P0.5)

## Goal

Freeze implementation scope first, then continue closure work. No horizontal expansion in this batch.

## Keep Modules (Stage-1)

1. `xianyu_adapter`
2. `zbj_adapter`
3. `channel_hub`
4. `order_center`
5. `delivery_center`
6. `finance_center`

## Pause Modules

1. Complex CRM deepening (lead/opportunity/quote/contract)
2. SKU system expansion
3. Inventory/WMS deepening
4. Asset management
5. Unified multi-platform listing center
6. BI dashboards and advanced reporting

## Why These Keeps/Pauses

- Current business value is in service-like order intake and settlement closure, not in warehouse-heavy expansion.
- Existing codebase is still scaffold-heavy for sync adapters; closure on core path has higher priority than adding domains.
- Locking scope reduces churn between docs, routes, and migrations.

## Current Repository Mapping

- Existing concrete implementation:
  - `apps/php-api` (Laravel APIs + migrations)
  - `apps/python-sync` (adapter/worker/scheduler skeleton with mock providers, including `xianyu` and `zbj`)
  - Raw ingest baseline for pull tasks (`raw_orders`, `raw_refunds`, `raw_listings`) is available.
- Existing docs:
  - `docs/api/*`
  - `docs/architecture/*`
- Stage-1 keep list is the target product boundary, not a statement that all six modules are fully implemented today.
