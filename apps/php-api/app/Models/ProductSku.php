<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSku extends Model
{
    protected $table = 'products_sku';

    protected $fillable = [
        'sku_code',
        'spu_id',
        'sku_name',
        'specs_json',
        'barcode',
        'cost_price',
        'cost_currency',
        'retail_price',
        'retail_currency',
        'weight',
        'size_json',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'specs_json' => 'array',
            'size_json' => 'array',
            'cost_price' => 'decimal:2',
            'retail_price' => 'decimal:2',
            'weight' => 'decimal:3',
        ];
    }
}
