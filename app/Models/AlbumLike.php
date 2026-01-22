<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AlbumLike extends Model
{
    use HasFactory;

    protected $connection = 'mysql_content';

    protected $fillable = [
        'album_id',
        'user_id',
    ];

    public $timestamps = false; // Schema has created_at only.

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Since it has only created_at, we might want to handle timestamps manually or disable default timestamps.
    // By default Laravel expects created_at and updated_at.
    
    public function album()
    {
        return $this->belongsTo(Album::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
