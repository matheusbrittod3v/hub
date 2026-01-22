<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Report extends Model
{
    use HasFactory;

    protected $connection = 'mysql_content';

    protected $fillable = [
        'album_id',
        'media_id',
        'user_id',
        'reason',
        'description',
        'status',
        'reviewed_at',
    ];

    public $timestamps = false; // Schema has created_at only.

    protected $casts = [
        'created_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function album()
    {
        return $this->belongsTo(Album::class);
    }

    public function mediaItem()
    {
        return $this->belongsTo(MediaItem::class, 'media_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
