<?php

return [
    'manual_run' => [
        'trigger_enabled' => (bool) env('SYNC_MANUAL_RUN_TRIGGER_ENABLED', true),
        'trigger_url' => env('PYTHON_SYNC_INTERNAL_URL', 'http://localhost:8100/internal/trigger/worker'),
        'trigger_timeout_seconds' => (float) env('SYNC_MANUAL_RUN_TRIGGER_TIMEOUT', 2.0),
    ],
];
