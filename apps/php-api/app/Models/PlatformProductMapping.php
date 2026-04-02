<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformProductMapping extends Model
{
    protected $table = 'platform_product_mappings';

    protected $fillable = [
        'shop_id',
        'spu_id',
        'sku_id',
        'platform_code',
        'site_code',
        'external_listing_id',
        'external_sku_id',
        'external_status',
        'raw_payload',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
