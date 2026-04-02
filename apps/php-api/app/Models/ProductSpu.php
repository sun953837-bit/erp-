<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpu extends Model
{
    protected $table = 'products_spu';

    protected $fillable = [
        'spu_code',
        'title',
        'brand',
        'category_name',
        'status',
        'source_of_truth',
    ];
}
