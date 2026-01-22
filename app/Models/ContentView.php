<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;

class ContentView extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'content_views';

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
        'viewable_id',
        'viewable_type',
        'ip_address',
        'user_agent',
        'referer',
        'country',
        'device_type',
        'viewed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    /**
     * Get the user who viewed the content.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the viewable model (Album, MediaItem, etc).
     */
    public function viewable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Register a view for content with duplicate prevention.
     */
    public static function registerView(
        string $viewableType, 
        int $viewableId, 
        ?int $userId = null, 
        ?Request $request = null
    ): bool {
        $ip = $request?->ip();
        $sessionKey = "viewed_{$viewableType}_{$viewableId}";
        
        // Check session to prevent counting on refresh
        if ($request?->session()?->has($sessionKey)) {
            return false;
        }
        
        // Check if same IP viewed in last 24 hours (for anonymous users)
        if (!$userId && $ip) {
            $recentView = self::where('viewable_type', $viewableType)
                ->where('viewable_id', $viewableId)
                ->where('ip_address', $ip)
                ->where('viewed_at', '>=', now()->subHours(24))
                ->exists();
            
            if ($recentView) {
                return false;
            }
        }
        
        // Detect device type
        $userAgent = $request?->userAgent() ?? '';
        $deviceType = self::detectDeviceType($userAgent);
        
        // Create view record
        self::create([
            'user_id' => $userId,
            'viewable_type' => $viewableType,
            'viewable_id' => $viewableId,
            'ip_address' => $ip,
            'user_agent' => substr($userAgent, 0, 500),
            'referer' => substr($request?->header('referer') ?? '', 0, 500),
            'device_type' => $deviceType,
            'viewed_at' => now(),
        ]);
        
        // Mark in session
        $request?->session()?->put($sessionKey, true);
        
        return true;
    }

    /**
     * Get view count for a content.
     */
    public static function countViews(string $viewableType, int $viewableId): int
    {
        return self::where('viewable_type', $viewableType)
            ->where('viewable_id', $viewableId)
            ->count();
    }

    /**
     * Get unique views (by IP) for a content.
     */
    public static function countUniqueViews(string $viewableType, int $viewableId): int
    {
        return self::where('viewable_type', $viewableType)
            ->where('viewable_id', $viewableId)
            ->distinct('ip_address')
            ->count('ip_address');
    }

    /**
     * Detect device type from user agent.
     */
    protected static function detectDeviceType(string $userAgent): string
    {
        $userAgent = strtolower($userAgent);
        
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $userAgent)) {
            return 'tablet';
        }
        
        if (preg_match('/(mobi|ipod|phone|blackberry|opera mini|iemobile|wpdesktop)/i', $userAgent)) {
            return 'mobile';
        }
        
        return 'desktop';
    }
}
