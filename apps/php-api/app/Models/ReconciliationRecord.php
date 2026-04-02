<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReconciliationRecord extends Model
{
    protected $table = 'reconciliation_records';

    protected $fillable = [
        'reconciliation_no',
        'service_order_id',
        'receivable_record_id',
        'refund_record_id',
        'delta_amount',
        'currency',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'delta_amount' => 'decimal:2',
        ];
    }
}
