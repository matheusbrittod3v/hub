<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MediaItem extends Model
{
    use HasFactory;

    protected $connection = 'mysql_content';

    protected $fillable = [
        'album_id',
        'album_title',
        'media_url',
        'media_type',
        'filename',
        'local_path',
        's3_path',
        'file_size',
        'width',
        'height',
        'duration',
        'thumbnail_url',
        'status',
        'download_attempts',
        'error_message',
        'downloaded_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'float',
        'download_attempts' => 'integer',
        'downloaded_at' => 'datetime',
    ];

    public function album()
    {
        return $this->belongsTo(Album::class);
    }
}
