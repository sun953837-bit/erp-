<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncReceiptLog extends Model
{
    protected $table = 'sync_receipt_logs';

    public $timestamps = false;

    protected $fillable = [
        'sync_task_id',
        'request_id',
        'phase',
        'http_status',
        'platform_code',
        'endpoint',
        'success',
        'accepted',
        'final_result',
        'external_id',
        'code',
        'message',
        'request_payload',
        'response_payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'accepted' => 'boolean',
            'final_result' => 'boolean',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
