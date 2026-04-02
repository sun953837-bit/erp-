# FineReport (帆软) Stage-1 Integration Guide

## Data Source

- DB:
  - source (transactional): controlled by `BI_ETL_SOURCE_CONNECTION` (fallback app default)
  - target (theme tables): controlled by `BI_ETL_TARGET_CONNECTION` (fallback source connection)
- Recommended account: read-only BI user
- Charset: `utf8mb4`
- Timezone: UTC (convert in report layer if needed)

## Stage-1 Theme Tables

- Dimensions:
  - `dim_platform`
  - `dim_shop`
  - `dim_customer`
  - `dim_service`
  - `dim_product`
  - `dim_date`
- Facts:
  - `fact_service_orders`
  - `fact_goods_orders`
  - `fact_after_sales`
  - `fact_settlements`
  - `fact_project_delivery`

## Refresh Strategy (Fixed)

- API/CLI mode `stage1`:
  - default incremental refresh
  - auto-upgrade to full refresh when `last_success_at` lag exceeds `BI_ETL_STAGE1_FULL_LAG_HOURS` (default 24h)
- Scheduler:
  - `bi:etl-refresh --mode=stage1`
  - `bi:etl-recover` for failure compensation
- Service source switch:
  - `READ_SERVICE_FROM_CANONICAL_ORDERS=true|false`
  - `READ_SERVICE_FROM_CANONICAL_ORDERS_FALLBACK=true|false`
- Dual-source compare summary:
  - `service_source_comparison` (from refresh/summary/monitor payload) to compare legacy `service_orders` and canonical `orders(order_type=service)`

## Quality & Delivery Signals

- `bi_etl_runs` key fields:
  - `last_effective_mode`
  - `last_strategy_reason`
  - `last_duration_ms`
  - `last_total_rows`
  - `last_zero_count_tables_json`
  - `last_quality_score`
  - `consecutive_failures`
  - `last_alert_level`
- Alert sink:
  - `notifications` (`biz_type=bi_etl`)
  - `audit_logs` (`action=bi_etl_alert_emitted`)
- Monitor API:
  - `GET /api/bi/etl/monitor`
- Monitor view:
  - `v_bi_etl_monitor`

## Suggested Report Datasets

1. Service order overview
- table: `fact_service_orders`
- dimensions: platform/shop/date/status
- metrics: `order_amount`, `received_amount`, `unpaid_amount`

2. After-sales analysis
- table: `fact_after_sales`
- dimensions: platform/shop/date/refund_status/source_type
- metrics: `refund_amount`

3. Goods order trend
- table: `fact_goods_orders`
- dimensions: platform/shop/date/status/customer
- metrics: `order_amount`, `item_count`

4. Settlement delta trend
- table: `fact_settlements`
- dimensions: date/platform/shop/settlement_status
- metrics: `delta_amount`

5. Delivery closure
- table: `fact_project_delivery`
- dimensions: delivery_type/delivery_status/date/platform
- metrics: `is_closed` rate

## Operational Checklist

1. Before report publish:
- ensure latest `bi_etl_runs.last_alert_level` is `OK` or `WARN`.
- ensure `consecutive_failures=0`.

2. If ETL fails:
- call `POST /api/bi/etl/recover` or run `php artisan bi:etl-recover`.
- confirm new successful run before refreshing report cache.

3. Read-only account and schema:
- Suggested read-only schema marker: `BI_READONLY_SCHEMA` (default `bi_readonly`)
- Suggested read-only username marker: `BI_READONLY_USERNAME` (default `bi_reader`)
- FineReport connection should only point to theme/fact tables and monitor view.
