<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundRecord extends Model
{
    protected $table = 'refund_records';

    protected $fillable = [
        'refund_no',
        'service_order_id',
        'payment_record_id',
        'amount',
        'currency',
        'status',
        'reason',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_at' => 'datetime',
        ];
    }
}
