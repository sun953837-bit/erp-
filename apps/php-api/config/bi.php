<?php

return [
    'source_connection' => (string) env('BI_ETL_SOURCE_CONNECTION', ''),
    'target_connection' => (string) env('BI_ETL_TARGET_CONNECTION', ''),

    'stage1' => [
        'full_refresh_max_lag_hours' => (int) env('BI_ETL_STAGE1_FULL_LAG_HOURS', 24),
        'incremental_window_days' => (int) env('BI_ETL_INCREMENTAL_WINDOW_DAYS', 3),
        'failure_recover_mode' => env('BI_ETL_FAILURE_RECOVER_MODE', 'full'),
        'alert_enabled' => filter_var((string) env('BI_ETL_ALERT_ENABLED', 'true'), FILTER_VALIDATE_BOOL),
        'alert_priority' => (int) env('BI_ETL_ALERT_PRIORITY', 1),
        'read_service_from_canonical_orders' => filter_var((string) env('READ_SERVICE_FROM_CANONICAL_ORDERS', 'false'), FILTER_VALIDATE_BOOL),
        'canonical_fallback_enabled' => filter_var((string) env('READ_SERVICE_FROM_CANONICAL_ORDERS_FALLBACK', 'true'), FILTER_VALIDATE_BOOL),
    ],

    'readonly' => [
        'schema' => (string) env('BI_READONLY_SCHEMA', 'bi_readonly'),
        'username' => (string) env('BI_READONLY_USERNAME', 'bi_reader'),
    ],
];
