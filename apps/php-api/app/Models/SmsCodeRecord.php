<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsCodeRecord extends Model
{
    protected $table = 'sms_code_records';

    public $timestamps = false;

    protected $fillable = [
        'phone',
        'purpose',
        'code_hash',
        'salt',
        'expires_at',
        'used_at',
        'ip',
        'user_agent',
        'send_status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
