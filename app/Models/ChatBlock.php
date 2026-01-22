<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatBlock extends Model
{
    protected $fillable = [
        'ip_address',
        'blocked_until',
        'reason',
        'reports_accumulator',
    ];

    protected $casts = [
        'blocked_until' => 'datetime',
    ];
}
