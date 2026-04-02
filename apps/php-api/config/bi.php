<?php

return [
    'stage1' => [
        'full_refresh_max_lag_hours' => (int) env('BI_ETL_STAGE1_FULL_LAG_HOURS', 24),
        'incremental_window_days' => (int) env('BI_ETL_INCREMENTAL_WINDOW_DAYS', 3),
        'failure_recover_mode' => env('BI_ETL_FAILURE_RECOVER_MODE', 'full'),
        'alert_enabled' => (bool) env('BI_ETL_ALERT_ENABLED', true),
        'alert_priority' => (int) env('BI_ETL_ALERT_PRIORITY', 1),
    ],
];
