<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsOrderFulfillment extends Model
{
    protected $table = 'goods_order_fulfillments';

    protected $fillable = [
        'order_id',
        'fulfillment_no',
        'logistics_status',
        'carrier',
        'tracking_no',
        'shipped_at',
        'delivered_at',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'meta_json' => 'array',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
