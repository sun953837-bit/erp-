<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRecord extends Model
{
    protected $table = 'payment_records';

    protected $fillable = [
        'payment_no',
        'service_order_id',
        'receivable_record_id',
        'amount',
        'currency',
        'paid_at',
        'channel',
        'reference_no',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }
}
