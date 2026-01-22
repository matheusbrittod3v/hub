<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ScrapingSession extends Model
{
    use HasFactory;

    protected $connection = 'mysql_content';

    public $timestamps = false; // Schema has started_at, ended_at, but not created_at/updated_at default names? No, schema says started_at defaults to CURRENT_TIMESTAMP, ended_at.
    // Actually, looking at schema: created_at DATETIME DEFAULT CURRENT_TIMESTAMP is MISSING.
    // It has started_at. So no default timestamps like created_at/updated_at unless I missed it.
    // Schema: 84: started_at, 85: ended_at.
    
    protected $fillable = [
        'started_at',
        'ended_at',
        'category',
        'start_page',
        'end_page',
        'current_page',
        'albums_found',
        'albums_scraped',
        'photos_found',
        'videos_found',
        'photos_downloaded',
        'videos_downloaded',
        'errors_count',
        'status',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'start_page' => 'integer',
        'end_page' => 'integer',
        'current_page' => 'integer',
        'albums_found' => 'integer',
        'albums_scraped' => 'integer',
        'photos_found' => 'integer',
        'videos_found' => 'integer',
        'photos_downloaded' => 'integer',
        'videos_downloaded' => 'integer',
        'errors_count' => 'integer',
    ];
}
