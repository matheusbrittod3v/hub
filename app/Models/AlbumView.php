<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AlbumView extends Model
{
    use HasFactory;

    protected $connection = 'mysql_content';

    protected $fillable = [
        'album_id',
        'user_id',
        'ip_address',
        'user_agent',
    ];

    public $timestamps = false; // Schema has created_at only.

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function album()
    {
        return $this->belongsTo(Album::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
