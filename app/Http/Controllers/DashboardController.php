<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\ContentLike;
use App\Models\ContentView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the user dashboard/profile - OPTIMIZED
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');

        // Get all user album IDs
        $albumIds = Album::where('user_id', $user->id)->pluck('id')->toArray();

        // OPTIMIZED: Get stats from new interaction tables
        $statsCacheKey = "user_stats_v2_{$user->id}";
        $stats = cache()->remember($statsCacheKey, 300, function () use ($user, $albumIds) {
            // Get album counts from mysql_content
            $albumStats = Album::where('user_id', $user->id)
                ->selectRaw('
                    COUNT(*) as total_albums,
                    COALESCE(SUM(photo_count), 0) as total_photos,
                    COALESCE(SUM(video_count), 0) as total_videos
                ')
                ->first();
            
            // Get likes count from content_likes table (mysql database)
            $totalLikes = 0;
            if (!empty($albumIds)) {
                $totalLikes = ContentLike::where('likeable_type', Album::class)
                    ->whereIn('likeable_id', $albumIds)
                    ->count();
            }
            
            // Get views count from content_views table (mysql database)
            $totalViews = 0;
            if (!empty($albumIds)) {
                $totalViews = ContentView::where('viewable_type', Album::class)
                    ->whereIn('viewable_id', $albumIds)
                    ->count();
            }
            
            return [
                'total_views' => (int) $totalViews,
                'total_likes' => (int) $totalLikes,
                'total_albums' => (int) $albumStats->total_albums,
                'total_photos' => (int) $albumStats->total_photos,
                'total_videos' => (int) $albumStats->total_videos,
            ];
        });

        // OPTIMIZED: Get albums with first media item in single query (no N+1)
        $albumIdsList = Album::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(50) // Limit for performance
            ->pluck('id')
            ->toArray();

        // Preload first media item for each album
        $mediaCache = [];
        if (!empty($albumIdsList)) {
            $placeholders = implode(',', array_fill(0, count($albumIdsList), '?'));
            $mediaItems = \DB::connection('mysql_content')
                ->select("
                    SELECT m.album_id, m.s3_path, m.local_path, m.thumbnail_url, m.media_type
                    FROM media_items m
                    INNER JOIN (
                        SELECT album_id, MIN(id) as min_id
                        FROM media_items
                        WHERE album_id IN ({$placeholders}) AND status = 'completed'
                        GROUP BY album_id
                    ) first_media ON m.album_id = first_media.album_id AND m.id = first_media.min_id
                ", $albumIdsList);

            foreach ($mediaItems as $item) {
                $mediaCache[$item->album_id] = $item;
            }
        }
        
        // Preload likes and views counts for each album
        $likesCache = [];
        $viewsCache = [];
        if (!empty($albumIdsList)) {
            // Get likes counts
            $likesCounts = ContentLike::where('likeable_type', Album::class)
                ->whereIn('likeable_id', $albumIdsList)
                ->selectRaw('likeable_id, COUNT(*) as count')
                ->groupBy('likeable_id')
                ->pluck('count', 'likeable_id')
                ->toArray();
            $likesCache = $likesCounts;
            
            // Get views counts
            $viewsCounts = ContentView::where('viewable_type', Album::class)
                ->whereIn('viewable_id', $albumIdsList)
                ->selectRaw('viewable_id, COUNT(*) as count')
                ->groupBy('viewable_id')
                ->pluck('count', 'viewable_id')
                ->toArray();
            $viewsCache = $viewsCounts;
        }

        // Get paginated albums
        $albums = Album::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(12)
            ->through(function ($album) use ($r2Url, $mediaCache, $likesCache, $viewsCache) {
                return $this->transformAlbumOptimized($album, $r2Url, $mediaCache, $likesCache, $viewsCache);
            });

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'albums' => $albums,
        ]);
    }

    /**
     * Transform album - OPTIMIZED version using preloaded cache
     */
    protected function transformAlbumOptimized(Album $album, string $r2Url, array $mediaCache, array $likesCache = [], array $viewsCache = []): array
    {
        $media = $mediaCache[$album->id] ?? null;
        
        $thumbnail = null;
        $thumbIsVideo = false;
        $previewUrl = null;

        if ($media) {
            // Build URL from cached media item
            $path = $media->thumbnail_url ?: $media->s3_path ?: $media->local_path;
            if ($path) {
                $thumbnail = str_starts_with($path, 'http') ? $path : $r2Url . '/' . ltrim($path, '/');
            }
            $thumbIsVideo = $media->media_type === 'video' && empty($media->thumbnail_url);
            
            // Preview URL for videos
            if ($media->media_type === 'video') {
                $videoPath = $media->s3_path ?: $media->local_path;
                $previewUrl = $videoPath ? (str_starts_with($videoPath, 'http') ? $videoPath : $r2Url . '/' . ltrim($videoPath, '/')) : null;
            }
        }
        
        // Get likes and views from cache or fallback to album column
        $likes = $likesCache[$album->id] ?? ($album->likes ?? 0);
        $views = $viewsCache[$album->id] ?? ($album->views ?? 0);

        return [
            'id' => $album->id,
            'album_id' => $album->album_id,
            'title' => $album->title ?: 'Sem título',
            'thumbnail' => $thumbnail,
            'thumb_is_video' => $thumbIsVideo,
            'preview_url' => $previewUrl,
            'photo_count' => $album->photo_count ?? 0,
            'video_count' => $album->video_count ?? 0,
            'views' => $views,
            'likes' => $likes,
            'author' => Auth::user()->username ?? Auth::user()->name ?? 'Você',
            'author_avatar' => Auth::user()->computed_avatar_url ?? Auth::user()->profile_photo_url ?? '/assets/images/default-avatar.jpg',
            'created_at' => $album->created_at?->diffForHumans(),
            'status' => $album->is_public ? 'Publicado' : 'Privado',
        ];
    }

    /**
     * Transform album (Legacy - kept for compatibility)
     */
    protected function transformAlbum(Album $album): array
    {
        $thumbInfo = $this->getAlbumThumbnailInfo($album);
        $previewUrl = $this->getAlbumPreview($album);

        return [
            'id' => $album->id,
            'album_id' => $album->album_id,
            'title' => $album->title ?: 'Sem título',
            'thumbnail' => $thumbInfo['url'],
            'thumb_is_video' => $thumbInfo['is_video'],
            'preview_url' => $previewUrl,
            'photo_count' => $album->photo_count ?? 0,
            'video_count' => $album->video_count ?? 0,
            'views' => $album->views ?? 0,
            'likes' => $album->likes ?? 0,
            'author' => Auth::user()->username ?? Auth::user()->name ?? 'Você',
            'author_avatar' => Auth::user()->computed_avatar_url ?? Auth::user()->profile_photo_url ?? '/assets/images/default-avatar.jpg',
            'created_at' => $album->created_at?->diffForHumans(),
            'status' => $album->is_public ? 'Publicado' : 'Privado',
        ];
    }

    // Helper methods (Duplicated from HomeController for now)
    protected function getAlbumThumbnailInfo(Album $album): array
    {
        $photo = $album->mediaItems()
            ->where('status', 'completed')
            ->where('media_type', 'photo')
            ->orderBy('id', 'asc')
            ->first();

        if ($photo) {
            $url = $this->getStorageUrl($photo->s3_path ?? $photo->local_path);
            return ['url' => $url, 'is_video' => false];
        }

        $video = $album->mediaItems()
            ->where('status', 'completed')
            ->where('media_type', 'video')
            ->orderBy('id', 'asc')
            ->first();

        if ($video) {
            if (!empty($video->thumbnail_url)) {
                return [
                    'url' => $this->getStorageUrl($video->thumbnail_url),
                    'is_video' => false
                ];
            }
            $url = $this->getStorageUrl($video->s3_path ?? $video->local_path);
            return ['url' => $url, 'is_video' => true];
        }

        return ['url' => null, 'is_video' => false];
    }

    protected function getAlbumPreview(Album $album): ?string
    {
        $video = $album->mediaItems()
            ->where('status', 'completed')
            ->where('media_type', 'video')
            ->first();

        if ($video) {
            return $this->getStorageUrl($video->s3_path ?? $video->local_path);
        }
        return null;
    }

    protected function getStorageUrl(?string $path, string $default = ''): string 
    {
        if (empty($path)) return '';
        if (str_starts_with($path, 'http')) return $path;
        if (str_starts_with($path, '/')) return $path;
        
        $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');
        return $r2Url . '/' . ltrim($path, '/');
    }
}
