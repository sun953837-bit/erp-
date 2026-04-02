<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceivableRecord extends Model
{
    protected $table = 'receivable_records';

    protected $fillable = [
        'receivable_no',
        'service_order_id',
        'amount',
        'received_amount',
        'currency',
        'status',
        'due_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'due_at' => 'datetime',
        ];
    }
}
