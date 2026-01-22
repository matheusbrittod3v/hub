<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\SlugService;

class Album extends Model
{
    use HasFactory;

    protected $connection = 'mysql_content';

    protected $fillable = [
        'album_id',
        'user_id',
        'url',
        'title',
        'slug',  // ⭐ Novo campo
        'description',
        'author',
        'author_url',
        'tags',
        'views',
        'likes',
        'photo_count',
        'video_count',
        'category',
        'is_public',
        'is_featured',
        'source',
        'scraped_at',
        'is_processed',
    ];

    /**
     * Boot do model - gera slug automaticamente
     */
    protected static function boot()
    {
        parent::boot();

        // Gerar slug antes de criar
        static::creating(function ($album) {
            if (empty($album->slug) && !empty($album->title)) {
                $album->slug = SlugService::generateWithoutCache($album->title, $album->tags);
            }
        });

        // Atualizar slug se o título mudar
        static::updating(function ($album) {
            if ($album->isDirty('title') || $album->isDirty('tags')) {
                $album->slug = SlugService::generateWithoutCache($album->title, $album->tags);
            }
        });
    }

    protected $casts = [
        'is_public' => 'boolean',
        'is_featured' => 'boolean',
        'is_processed' => 'boolean',
        'scraped_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mediaItems()
    {
        return $this->hasMany(MediaItem::class);
    }

    public function likes()
    {
        return $this->hasMany(AlbumLike::class);
    }

    public function views()
    {
        return $this->hasMany(AlbumView::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }

    /**
     * ========================================================================
     * HELPERS DE URL E SLUG
     * ========================================================================
     */

    /**
     * Retorna a URL completa do álbum com slug
     */
    public function getUrlAttribute(): string
    {
        return SlugService::generateAlbumUrl(
            $this->album_id,
            $this->title,
            $this->tags
        );
    }

    /**
     * Retorna o slug atual ou gera um novo se não existir
     */
    public function getSlugAttribute($value): string
    {
        if (empty($value) && !empty($this->title)) {
            return SlugService::generateFromTitle($this->title, $this->tags);
        }
        return $value ?? '';
    }

    /**
     * Gera URL absoluta para o álbum
     */
    public function getFullUrlAttribute(): string
    {
        return url($this->url);
    }

    /**
     * Verifica se o slug fornecido está correto para este álbum
     */
    public function hasValidSlug(?string $providedSlug): bool
    {
        if (empty($providedSlug)) {
            return true; // Slug é opcional na URL
        }

        $correctSlug = $this->slug ?: SlugService::generateFromTitle($this->title, $this->tags);
        return $providedSlug === $correctSlug;
    }
}
