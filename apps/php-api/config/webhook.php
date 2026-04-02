<?php

return [
    'require_timestamp' => filter_var((string) env('WEBHOOK_REQUIRE_TIMESTAMP', 'true'), FILTER_VALIDATE_BOOL),
    'allowed_drift_seconds' => max(10, (int) env('WEBHOOK_ALLOWED_DRIFT_SECONDS', 300)),
    'max_payload_bytes' => max(1024, (int) env('WEBHOOK_MAX_PAYLOAD_BYTES', 131072)),
    'max_error_message_length' => max(128, (int) env('WEBHOOK_MAX_ERROR_MESSAGE_LENGTH', 1000)),
];
