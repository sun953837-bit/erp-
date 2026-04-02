# Migration Truth (P0.5)

This file is the single source of truth for migration status in `codex53_tmp`.

## Actual Migration Source

- Runtime migration directory: `apps/php-api/database/migrations`
- Command:
  - `docker compose exec php-api php artisan migrate --force`

## Ordered Migration Chain

1. `2026_02_22_000001_create_users_roles_permissions_tables.php`
2. `2026_02_22_000002_create_shops_and_platform_configs_tables.php`
3. `2026_02_22_000003_create_products_tables.php`
4. `2026_02_22_000004_create_platform_product_mappings_table.php`
5. `2026_02_22_000005_create_sync_tasks_and_receipt_logs_tables.php`
6. `2026_02_22_000006_create_sms_code_records_table.php`
7. `2026_02_22_000007_create_audit_logs_and_notifications_tables.php`
8. `2026_04_02_000008_create_webhook_events_table.php`
9. `2026_04_02_000009_create_raw_channel_tables.php`
10. `2026_04_02_000010_create_raw_services_table.php`
11. `2026_04_02_000011_create_service_order_finance_delivery_tables.php`
12. `2026_04_02_000012_create_bi_dimension_and_fact_tables.php`
13. `2026_04_02_000013_create_bi_etl_runs_table.php`
14. `2026_04_02_000014_add_external_refund_fields_to_refund_records.php`

## Current HEAD

- HEAD migration file: `2026_04_02_000014_add_external_refund_fields_to_refund_records.php`

## Scope Notes

- This repo currently has no `infra/migrations/*.sql` execution chain.
- Any placeholder plans (for SKU/inventory/asset domains) are not active migrations in current codebase.
- New migrations must continue from this HEAD and should be created in Laravel migration format under `apps/php-api/database/migrations`.
