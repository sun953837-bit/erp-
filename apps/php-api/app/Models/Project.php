<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $table = 'projects';

    protected $fillable = [
        'project_no',
        'service_order_id',
        'name',
        'status',
        'owner',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'meta_json' => 'array',
        ];
    }
}
