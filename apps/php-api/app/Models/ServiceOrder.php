<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceOrder extends Model
{
    protected $table = 'service_orders';

    protected $fillable = [
        'order_no',
        'platform_code',
        'shop_id',
        'external_order_id',
        'service_name',
        'customer_name',
        'customer_id',
        'currency',
        'amount',
        'status',
        'delivery_mode',
        'project_id',
        'ticket_id',
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

    public function receivables(): HasMany
    {
        return $this->hasMany(ReceivableRecord::class, 'service_order_id');
    }

    public function latestReceivable(): HasOne
    {
        return $this->hasOne(ReceivableRecord::class, 'service_order_id')->latestOfMany();
    }
}
