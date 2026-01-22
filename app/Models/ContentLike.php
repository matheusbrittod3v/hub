<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentLike extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'content_likes';

    /**
     * The database connection (banco padrão).
     */
    protected $connection = 'mysql';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'ip_address',
        'likeable_id',
        'likeable_type',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }

    /**
     * Get the user who liked the content (may be null for anonymous likes).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the likeable model (Album, MediaItem, etc).
     */
    public function likeable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Check if a user or IP already liked a specific content.
     */
    public static function hasLiked(?int $userId, ?string $ipAddress, string $likeableType, int $likeableId): bool
    {
        $query = self::where('likeable_type', $likeableType)
            ->where('likeable_id', $likeableId);
        
        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($ipAddress) {
            $query->whereNull('user_id')->where('ip_address', $ipAddress);
        } else {
            return false;
        }
        
        return $query->exists();
    }

    /**
     * Toggle like for a content.
     * Supports both logged-in users and anonymous users (by IP).
     */
    public static function toggleLike(?int $userId, ?string $ipAddress, string $likeableType, int $likeableId): bool
    {
        // Build query to find existing like
        $query = self::where('likeable_type', $likeableType)
            ->where('likeable_id', $likeableId);
        
        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($ipAddress) {
            $query->whereNull('user_id')->where('ip_address', $ipAddress);
        } else {
            return false; // Can't like without identifier
        }
        
        $existing = $query->first();

        if ($existing) {
            $existing->delete();
            return false; // unliked
        }

        self::create([
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'likeable_type' => $likeableType,
            'likeable_id' => $likeableId,
        ]);
        
        return true; // liked
    }

    /**
     * Get like count for a content.
     */
    public static function countLikes(string $likeableType, int $likeableId): int
    {
        return self::where('likeable_type', $likeableType)
            ->where('likeable_id', $likeableId)
            ->count();
    }

    /**
     * Check if like is from a logged-in user.
     */
    public function isFromUser(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Check if like is anonymous (from IP only).
     */
    public function isAnonymous(): bool
    {
        return $this->user_id === null;
    }
}
