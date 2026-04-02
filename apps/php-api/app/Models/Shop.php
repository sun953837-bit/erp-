<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $table = 'shops';

    protected $fillable = [
        'shop_code',
        'shop_name',
        'platform_code',
        'site_code',
        'currency',
        'timezone',
        'status',
        'owner_name',
        'owner_phone',
    ];
}
