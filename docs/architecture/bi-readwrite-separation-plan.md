# BI Read/Write Separation Plan (Stage-1.5)

## Goal

Move BI refresh output from transactional write path toward configurable target connection while keeping Stage-1 ETL logic stable.

## Connection Topology

- Source connection:
  - env: `BI_ETL_SOURCE_CONNECTION`
  - fallback: `database.default`
  - used for reading transactional/canonical source data.
- Target connection:
  - env: `BI_ETL_TARGET_CONNECTION`
  - fallback: source connection
  - used for writing:
    - `dim_*`
    - `fact_*`
    - `bi_etl_runs`

## Service Source Cutover

- Flag: `READ_SERVICE_FROM_CANONICAL_ORDERS`
  - `false` (default): read service BI source from `service_orders`.
  - `true`: read from `orders(order_type=service)`.
- Fallback: `READ_SERVICE_FROM_CANONICAL_ORDERS_FALLBACK`
  - when enabled, if canonical source is not ready, auto-fallback to legacy source.

## Monitoring

- API:
  - `GET /api/bi/etl/summary`
  - `GET /api/bi/etl/monitor`
- View:
  - `v_bi_etl_monitor`
- Alert sinks:
  - `notifications` (`biz_type=bi_etl`)
  - `audit_logs` (`action=bi_etl_alert_emitted`)

## Rollback

1. Set `READ_SERVICE_FROM_CANONICAL_ORDERS=false`.
2. Keep `BI_ETL_TARGET_CONNECTION` unchanged to preserve theme data continuity.
3. Trigger manual recover:
   - `php artisan bi:etl-recover`
4. Validate `last_alert_level` and `consecutive_failures`.
