<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncTask extends Model
{
    protected $table = 'sync_tasks';

    protected $fillable = [
        'task_no',
        'task_type',
        'biz_type',
        'biz_id',
        'shop_id',
        'platform_code',
        'site_code',
        'status',
        'idempotency_key',
        'payload_json',
        'result_summary_json',
        'retry_count',
        'max_retry_count',
        'last_error_code',
        'last_error_message',
        'next_retry_at',
        'accepted_at',
        'finished_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'result_summary_json' => 'array',
            'next_retry_at' => 'datetime',
            'accepted_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
