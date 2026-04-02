<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'order_no',
        'order_type',
        'platform_code',
        'shop_id',
        'external_order_id',
        'legacy_service_order_id',
        'subject',
        'customer_name',
        'currency',
        'amount',
        'status',
        'delivery_mode',
        'meta_json',
        'confirmed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta_json' => 'array',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function goodsFulfillments(): HasMany
    {
        return $this->hasMany(GoodsOrderFulfillment::class, 'order_id');
    }
}
