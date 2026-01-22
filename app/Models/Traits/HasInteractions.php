<?php

namespace App\Models\Traits;

use App\Models\ContentLike;
use App\Models\ContentView;
use App\Models\ContentReport;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;

trait HasInteractions
{
    /**
     * Get all likes for this content.
     */
    public function contentLikes(): MorphMany
    {
        return $this->morphMany(ContentLike::class, 'likeable');
    }

    /**
     * Get all views for this content.
     */
    public function contentViews(): MorphMany
    {
        return $this->morphMany(ContentView::class, 'viewable');
    }

    /**
     * Get all reports for this content.
     */
    public function contentReports(): MorphMany
    {
        return $this->morphMany(ContentReport::class, 'reportable');
    }

    /**
     * Get total like count.
     */
    public function getLikeCountAttribute(): int
    {
        return $this->contentLikes()->count();
    }

    /**
     * Get total view count.
     */
    public function getViewCountAttribute(): int
    {
        return $this->contentViews()->count();
    }

    /**
     * Get unique view count (by IP).
     */
    public function getUniqueViewCountAttribute(): int
    {
        return $this->contentViews()->distinct('ip_address')->count('ip_address');
    }

    /**
     * Get pending report count.
     */
    public function getPendingReportCountAttribute(): int
    {
        return $this->contentReports()->where('status', 'pending')->count();
    }

    /**
     * Check if a user has liked this content.
     */
    public function isLikedBy(?int $userId): bool
    {
        if (!$userId) return false;
        return $this->contentLikes()->where('user_id', $userId)->exists();
    }

    /**
     * Toggle like for this content.
     */
    public function toggleLike(int $userId): bool
    {
        $morphClass = $this->getMorphClass();
        return ContentLike::toggleLike($userId, $morphClass, $this->id);
    }

    /**
     * Register a view for this content.
     */
    public function registerView(?int $userId = null, ?Request $request = null): bool
    {
        $morphClass = $this->getMorphClass();
        return ContentView::registerView($morphClass, $this->id, $userId, $request);
    }

    /**
     * Report this content.
     */
    public function report(
        string $reason,
        ?string $description = null,
        ?int $reporterId = null,
        ?Request $request = null
    ): ContentReport {
        $morphClass = $this->getMorphClass();
        return ContentReport::createReport(
            $morphClass,
            $this->id,
            $reason,
            $description,
            $reporterId,
            $request
        );
    }

    /**
     * Check if content has pending reports.
     */
    public function hasPendingReports(): bool
    {
        $morphClass = $this->getMorphClass();
        return ContentReport::hasPendingReports($morphClass, $this->id);
    }

    /**
     * Get the morph class name.
     */
    protected function getMorphClass(): string
    {
        return get_class($this);
    }
}
