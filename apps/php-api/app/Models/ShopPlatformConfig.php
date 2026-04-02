<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPlatformConfig extends Model
{
    protected $table = 'shop_platform_configs';

    protected $fillable = [
        'shop_id',
        'auth_mode',
        'app_key_encrypted',
        'app_secret_encrypted',
        'client_id',
        'client_secret_encrypted',
        'refresh_token_encrypted',
        'key_version',
        'is_configured',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_configured' => 'boolean',
            'key_version' => 'integer',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }
}
