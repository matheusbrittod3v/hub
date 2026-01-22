<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    protected string $r2Url;

    protected array $userAvatarCache = [];

    protected array $mediaCache = [];

    public function __construct()
    {
        $this->r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');
    }

    /**
     * Display the home page with albums - OPTIMIZED with raw SQL
     */
    /**
     * Display the home page with albums - OPTIMIZED with raw SQL
     */
    public function index(Request $request): Response
    {
        $perPage = 24;
        $page = max(1, (int) $request->get('page', 1));
        $search = trim($request->get('q', ''));
        $offset = ($page - 1) * $perPage;

        // Build WHERE clause for search
        $whereClause = 'is_public = 1';
        $params = [];

        if (! empty($search)) {
            $searchTerm = '%'.$search.'%';
            $whereClause .= ' AND (title LIKE ? OR tags LIKE ? OR author LIKE ?)';
            $params = [$searchTerm, $searchTerm, $searchTerm];
        }

        // Query 1: Get total count for pagination (cached for 1 min)
        $countCacheKey = 'home_count_'.md5($search);
        $total = cache()->remember($countCacheKey, 60, function () use ($whereClause, $params) {
            $result = DB::connection('mysql_content')
                ->selectOne("SELECT COUNT(*) as total FROM albums WHERE {$whereClause}", $params);

            return $result->total ?? 0;
        });

        // Query 2: Get albums with only needed columns
        $albums = DB::connection('mysql_content')
            ->select("
                SELECT id, album_id, user_id, title, slug, author, photo_count, video_count, created_at,
                       0 as views, 0 as likes -- Placeholders, populated below
                FROM albums 
                WHERE {$whereClause}
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ", array_merge($params, [$perPage, $offset]), false);

        if (empty($albums)) {
            return $this->renderEmpty($search);
        }

        // Extract album IDs and user IDs
        $albumIds = array_column($albums, 'id');
        $userIds = array_unique(array_filter(array_column($albums, 'user_id')));

        // Query 3: Get real likes and views counts from default DB (cross-database query workaround)
        $this->populateInteractionStats($albums, $albumIds);

        // Query 4: Get first media item for each album (single query instead of N+1)
        $this->preloadMediaItems($albumIds);

        // Query 5: Get user avatars
        $this->preloadUserAvatars($userIds);

        // Transform albums with minimal overhead
        $transformedAlbums = [];
        foreach ($albums as $album) {
            $transformedAlbums[] = $this->transformAlbumFast($album);
        }

        // Build pagination data
        $lastPage = max(1, (int) ceil($total / $perPage));
        $paginationData = [
            'data' => $transformedAlbums,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'prev_page_url' => $page > 1 ? $this->buildPageUrl($page - 1, $search) : null,
            'next_page_url' => $page < $lastPage ? $this->buildPageUrl($page + 1, $search) : null,
            'links' => $this->buildPaginationLinks($page, $lastPage, $search),
        ];

        return Inertia::render('Welcome', [
            'albums' => $paginationData,
            'search' => $search,
            'seo' => [
                'title' => ! empty($search) ? "Resultados para {$search} | ".config('app.name') : 'Melhores Vídeos e Fotos Pornô | '.config('app.name'),
                'description' => 'Milhares de vídeos e fotos pornô grátis em HD. As melhores categorias e modelos atualizados diariamente.',
                'keywords' => 'porn, grátis, hd, vídeos, fotos, '.$search,
            ],
            'canLogin' => true,
            'canRegister' => true,
            'laravelVersion' => app()->version(),
            'phpVersion' => PHP_VERSION,
        ]);
    }

    /**
     * Populate albums with real views and likes from interaction tables
     */
    protected function populateInteractionStats(array $albums, array $albumIds): void
    {
        if (empty($albumIds)) {
            return;
        }

        // Get likes
        $likes = \App\Models\ContentLike::where('likeable_type', \App\Models\Album::class)
            ->whereIn('likeable_id', $albumIds)
            ->selectRaw('likeable_id, COUNT(*) as count')
            ->groupBy('likeable_id')
            ->pluck('count', 'likeable_id')
            ->toArray();

        // Get views
        $views = \App\Models\ContentView::where('viewable_type', \App\Models\Album::class)
            ->whereIn('viewable_id', $albumIds)
            ->selectRaw('viewable_id, COUNT(*) as count')
            ->groupBy('viewable_id')
            ->pluck('count', 'viewable_id')
            ->toArray();

        // Merge into objects
        foreach ($albums as $album) {
            $album->likes = $likes[$album->id] ?? 0;
            $album->views = $views[$album->id] ?? 0;
        }
    }

    /**
     * Preload media items for all albums in single query
     */
    protected function preloadMediaItems(array $albumIds): void
    {
        if (empty($albumIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));

        // Get first photo OR video thumbnail for each album
        $media = DB::connection('mysql_content')
            ->select("
                SELECT 
                    m.album_id,
                    COALESCE(m.thumbnail_url, m.s3_path, m.local_path) as thumb_path,
                    m.media_type,
                    m.s3_path as video_path
                FROM media_items m
                INNER JOIN (
                    SELECT album_id, MIN(id) as min_id
                    FROM media_items
                    WHERE album_id IN ({$placeholders}) AND status = 'completed'
                    GROUP BY album_id
                ) first_media ON m.album_id = first_media.album_id AND m.id = first_media.min_id
            ", $albumIds);

        foreach ($media as $item) {
            $this->mediaCache[$item->album_id] = $item;
        }
    }

    /**
     * Preload user avatars in single query
     */
    protected function preloadUserAvatars(array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        $users = DB::connection('mysql')
            ->select("SELECT id, avatar_url FROM users WHERE id IN ({$placeholders})", $userIds);

        foreach ($users as $user) {
            $this->userAvatarCache[$user->id] = $user->avatar_url;
        }
    }

    /**
     * Transform album with pre-cached data (no additional queries)
     */
    protected function transformAlbumFast(object $album): array
    {
        // Get cached media info
        $media = $this->mediaCache[$album->id] ?? null;

        $thumbnail = '/assets/images/placeholder.jpg';
        $thumbIsVideo = false;
        $previewUrl = null;

        if ($media) {
            // Build thumbnail URL
            if (! empty($media->thumb_path)) {
                $thumbnail = $this->buildUrl($media->thumb_path);
                $thumbIsVideo = $media->media_type === 'video' && empty($media->thumbnail_url);
            }

            // Build preview URL for videos
            if ($media->media_type === 'video' && ! empty($media->video_path)) {
                $previewUrl = $this->buildUrl($media->video_path);
            }
        }

        // Get cached avatar
        $authorAvatar = '/assets/images/default-avatar.jpg';
        if (! empty($album->user_id) && isset($this->userAvatarCache[$album->user_id])) {
            $avatarPath = $this->userAvatarCache[$album->user_id];
            if (! empty($avatarPath)) {
                $authorAvatar = $this->buildUrl($avatarPath);
            }
        }

        return [
            'id' => $album->id,
            'album_id' => $album->album_id,
            'slug' => $album->slug ?? null,
            'title' => $album->title ?: 'Sem título',
            'thumbnail' => $thumbnail,
            'thumb_is_video' => $thumbIsVideo,
            'preview_url' => $previewUrl,
            'photo_count' => $album->photo_count ?? 0,
            'video_count' => $album->video_count ?? 0,
            'views' => $album->views ?? 0,
            'likes' => $album->likes ?? 0,
            'author' => $album->author ?? 'Anônimo',
            'author_avatar' => $authorAvatar,
            'created_at' => $this->timeAgo($album->created_at),
        ];
    }

    /**
     * Build full URL from path
     */
    protected function buildUrl(?string $path): string
    {
        if (empty($path)) {
            return '';
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->r2Url.'/'.ltrim($path, '/');
    }

    /**
     * Fast time ago calculation without Carbon overhead
     */
    protected function timeAgo(?string $datetime): string
    {
        if (empty($datetime)) {
            return '';
        }

        $time = strtotime($datetime);
        $diff = time() - $time;

        if ($diff < 60) {
            return 'agora';
        }
        if ($diff < 3600) {
            return floor($diff / 60).' min';
        }
        if ($diff < 86400) {
            return floor($diff / 3600).'h';
        }
        if ($diff < 604800) {
            return floor($diff / 86400).'d';
        }
        if ($diff < 2592000) {
            return floor($diff / 604800).' sem';
        }
        if ($diff < 31536000) {
            return floor($diff / 2592000).' mês';
        }

        return floor($diff / 31536000).' ano';
    }

    /**
     * Build page URL with search parameter
     */
    protected function buildPageUrl(int $page, string $search): string
    {
        $url = route('home').'?page='.$page;
        if (! empty($search)) {
            $url .= '&q='.urlencode($search);
        }

        return $url;
    }

    /**
     * Build pagination links array
     */
    protected function buildPaginationLinks(int $current, int $last, string $search): array
    {
        $links = [];

        // Previous
        $links[] = [
            'url' => $current > 1 ? $this->buildPageUrl($current - 1, $search) : null,
            'label' => '&laquo; Anterior',
            'active' => false,
        ];

        // Page numbers (show 5 around current)
        $start = max(1, $current - 2);
        $end = min($last, $current + 2);

        for ($i = $start; $i <= $end; $i++) {
            $links[] = [
                'url' => $this->buildPageUrl($i, $search),
                'label' => (string) $i,
                'active' => $i === $current,
            ];
        }

        // Next
        $links[] = [
            'url' => $current < $last ? $this->buildPageUrl($current + 1, $search) : null,
            'label' => 'Próximo &raquo;',
            'active' => false,
        ];

        return $links;
    }

    /**
     * Render empty state
     */
    protected function renderEmpty(string $search): Response
    {
        return Inertia::render('Welcome', [
            'albums' => [
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 24,
                'total' => 0,
                'prev_page_url' => null,
                'next_page_url' => null,
                'links' => [],
            ],
            'search' => $search,
            'canLogin' => true,
            'canRegister' => true,
            'laravelVersion' => app()->version(),
            'phpVersion' => PHP_VERSION,
        ]);
    }
}
