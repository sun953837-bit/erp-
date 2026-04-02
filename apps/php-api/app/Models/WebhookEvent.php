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
            'processed_at' => 'datetime',
        ];
    }
}
