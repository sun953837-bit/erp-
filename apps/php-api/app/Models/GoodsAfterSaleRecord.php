<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsAfterSaleRecord extends Model
{
    protected $table = 'goods_after_sale_records';

    protected $fillable = [
        'after_sale_no',
        'order_id',
        'order_item_id',
        'external_after_sale_id',
        'after_sale_type',
        'status',
        'reason',
        'amount',
        'currency',
        'requested_at',
        'resolved_at',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'meta_json' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
