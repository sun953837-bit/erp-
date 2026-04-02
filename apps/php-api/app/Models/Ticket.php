<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $table = 'tickets';

    protected $fillable = [
        'ticket_no',
        'service_order_id',
        'title',
        'status',
        'assignee',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'meta_json' => 'array',
        ];
    }
}
