<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $table = 'webhook_events';

    protected $fillable = [
        'platform_code',
        'event_key',
        'signature',
        'request_timestamp',
        'received_at',
        'status',
        'attempts',
        'payload_json',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'payload_json' => 'array',
            'request_timestamp' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
