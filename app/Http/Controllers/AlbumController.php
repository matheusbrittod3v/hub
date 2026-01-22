<?php

namespace App\Http\Controllers;

use App\Jobs\ReplicateDatabaseJob;
use App\Models\Album;
use App\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AlbumController extends Controller
{
    /**
     * Show the album creation form
     */
    public function create(): Response
    {
        return Inertia::render('Album/Create');
    }

    /**
     * Store a new album and media
     */
    public function store(Request $request)
    {
        // Security: Validate input with strict rules
        $request->validate([
            'title' => 'required|string|max:255|regex:/^[^<>]*$/', // No HTML tags
            'description' => 'nullable|string|max:5000',
            'status' => 'required|in:public,private',
            'files' => 'required|array|min:1|max:50', // Max 50 files per upload
            'files.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp,mp4,mov,avi,mkv',
                'max:512000', // Max 500MB per file
            ],
        ], [
            'title.regex' => 'O título não pode conter caracteres especiais.',
            'files.max' => 'Você pode enviar no máximo 50 arquivos por vez.',
        ]);

        // Security: Verify MIME type matches extension (prevent double-extension attacks)
        foreach ($request->file('files') as $file) {
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();

            $allowedMimes = [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
                'mp4' => ['video/mp4'],
                'mov' => ['video/quicktime'],
                'avi' => ['video/x-msvideo', 'video/avi'],
                'mkv' => ['video/x-matroska'],
            ];

            if (! isset($allowedMimes[$extension]) || ! in_array($mimeType, $allowedMimes[$extension])) {
                return back()->withErrors(['files' => "Arquivo '{$file->getClientOriginalName()}' tem tipo inválido."]);
            }

            // Security: Check for embedded PHP or scripts in filenames
            $filename = $file->getClientOriginalName();
            if (preg_match('/(\.php|\.phtml|\.phar|<script|javascript:)/i', $filename)) {
                return back()->withErrors(['files' => 'Nome de arquivo não permitido.']);
            }
        }

        // Configure explicit Write and Read connections dynamically
        $baseConfig = config('database.connections.mysql_content');
        unset($baseConfig['read'], $baseConfig['write'], $baseConfig['sticky']);

        // Write Connection (mysql_content_write)
        if (! config('database.connections.mysql_content_write')) {
            $writeConfig = array_merge($baseConfig, config('database.connections.mysql_content.write') ?? []);
            config(['database.connections.mysql_content_write' => $writeConfig]);
        }

        // Read Connection (mysql_content_read) - for dual write
        if (! config('database.connections.mysql_content_read')) {
            $readConfig = array_merge($baseConfig, config('database.connections.mysql_content.read') ?? []);
            config(['database.connections.mysql_content_read' => $readConfig]);
        }

        DB::connection('mysql_content_write')->beginTransaction();
        try {
            // 1. Create Album on WRITE database (matching legacy PHP schema)
            $user = $request->user();
            $publicAlbumId = Str::random(12);

            $albumData = [
                'album_id' => $publicAlbumId,
                'user_id' => $user->id,
                'title' => $request->title,
                'description' => $request->description,
                'author' => $user->name,
                'author_url' => url('/profile/'.$user->id),
                'tags' => '',
                'category' => 'straight',
                'is_public' => $request->status === 'public' ? 1 : 0,
                'source' => 'user',
                'is_processed' => 1,
                'views' => 0,
                'likes' => 0,
                'video_count' => 0,
                'photo_count' => 0,
            ];

            $albumId = DB::connection('mysql_content_write')->table('albums')->insertGetId($albumData);
            $albumData['id'] = $albumId;

            // 1.1 Dual Write: Replicate Album to READ database
            try {
                DB::connection('mysql_content_read')->table('albums')->insert($albumData);
            } catch (\Exception $e) {
                \Log::warning('Dual Write (Album to Read) failed: '.$e->getMessage());
            }

            // 2. Process Files
            $videoCount = 0;
            $photoCount = 0;

            foreach ($request->file('files') as $file) {
                $mime = $file->getMimeType();
                $type = str_starts_with($mime, 'video/') ? 'video' : 'photo';

                $path = 'uploads/'.$request->user()->id.'/'.date('Y/m').'/'.Str::random(20).'.'.$file->getClientOriginalExtension();

                // Upload to R2
                Storage::disk('r2')->put($path, file_get_contents($file), 'public');

                // Build R2 URL
                $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');
                $mediaUrl = $r2Url.'/'.$path;

                // Create MediaItem on WRITE database (matching legacy PHP schema)
                $mediaData = [
                    'album_id' => $albumId,
                    'album_title' => $request->title,
                    'media_url' => $mediaUrl,
                    'media_type' => $type,
                    'filename' => $file->getClientOriginalName(),
                    'local_path' => null,
                    's3_path' => $path,
                    'file_size' => $file->getSize(),
                    'status' => 'completed',
                ];

                $mediaId = DB::connection('mysql_content_write')->table('media_items')->insertGetId($mediaData);
                $mediaData['id'] = $mediaId;

                // 2.1 Dual Write: Replicate MediaItem to READ database
                try {
                    DB::connection('mysql_content_read')->table('media_items')->insert($mediaData);
                } catch (\Exception $e) {
                    \Log::warning('Dual Write (Media to Read) failed: '.$e->getMessage());
                }

                if ($type === 'video') {
                    $videoCount++;
                } else {
                    $photoCount++;
                }
            }

            // 3. Update counts on WRITE database
            DB::connection('mysql_content_write')->table('albums')
                ->where('id', $albumId)
                ->update([
                    'video_count' => $videoCount,
                    'photo_count' => $photoCount,
                ]);

            // 3.1 Dual Write: Update counts on READ database
            try {
                DB::connection('mysql_content_read')->table('albums')
                    ->where('id', $albumId)
                    ->update([
                        'video_count' => $videoCount,
                        'photo_count' => $photoCount,
                    ]);
            } catch (\Exception $e) {
                \Log::warning('Dual Write (Update Counts on Read) failed: '.$e->getMessage());
            }

            DB::connection('mysql_content_write')->commit();

            // 4. Invalidate user stats cache (important for dashboard)
            cache()->forget("user_stats_{$user->id}");

            // 5. Trigger Replication Job (Safety Net)
            try {
                ReplicateDatabaseJob::dispatch('incremental', now()->subMinutes(5));
            } catch (\Exception $e) {
                \Log::warning('Replication Job trigger failed: '.$e->getMessage());
            }

            return redirect()->route('album.show', $publicAlbumId)
                ->with('success', 'Álbum criado com sucesso!');

        } catch (\Exception $e) {
            DB::connection('mysql_content_write')->rollBack();
            \Log::error('Create Album Error: '.$e->getMessage());

            return back()->withErrors(['message' => 'Erro ao criar álbum: '.$e->getMessage()]);
        }
    }

    /**
     * Show the album page - OPTIMIZED with raw SQL
     */
    public function show(Request $request, string $albumId)
    {
        $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');

        // Try cache first for album data (5 min cache)
        $cacheKey = 'album_show_'.$albumId;
        $cachedData = cache()->get($cacheKey);

        if ($cachedData && ! $request->has('nocache')) {
            // Still need to check privacy for cached data
            if (! $cachedData['is_public'] && $request->user()?->id !== $cachedData['user_id']) {
                abort(404);
            }

            // Update counts in real-time even for cached data
            $realLikes = \App\Models\ContentLike::where('likeable_type', \App\Models\Album::class)
                ->where('likeable_id', $cachedData['id'])
                ->count();

            $realViews = \App\Models\ContentView::where('viewable_type', \App\Models\Album::class)
                ->where('viewable_id', $cachedData['id'])
                ->count();

            $cachedData['album']['likes'] = $realLikes;
            $cachedData['album']['views'] = $realViews;

            // Register view and return cached response
            $this->registerViewAsync((object) ['id' => $cachedData['id']], $request);

            return Inertia::render('Album/Show', [
                'album' => $cachedData['album'],
                'videos' => $cachedData['videos'],
                'photos' => $cachedData['photos'],
                'relatedAlbums' => [], // Lazy load via API
            ]);
        }

        // Query 1: Get album with only needed columns (faster than SELECT *)
        $album = DB::connection('mysql_content')
            ->selectOne('
                SELECT id, album_id, user_id, title, description, slug, author, author_url, 
                       tags, views, likes, is_public, created_at
                FROM albums 
                WHERE album_id = ? 
                LIMIT 1
            ', [$albumId], false);

        if (! $album) {
            abort(404);
        }

        // Privacy check BEFORE any more queries
        if (! $album->is_public && $request->user()?->id !== $album->user_id) {
            abort(404);
        }

        // Query 2: Get author avatar (only if user_id exists)
        $authorAvatar = '/assets/images/default-avatar.jpg';
        if (! empty($album->user_id)) {
            $user = DB::connection('mysql')
                ->selectOne('SELECT avatar_url FROM users WHERE id = ? LIMIT 1', [$album->user_id]);
            if ($user && ! empty($user->avatar_url)) {
                $authorAvatar = str_starts_with($user->avatar_url, 'http')
                    ? $user->avatar_url
                    : $r2Url.'/'.ltrim($user->avatar_url, '/');
            }
        }

        // Query 3: Get media items with only needed columns
        $mediaItems = DB::connection('mysql_content')
            ->select("
                SELECT id, media_type, s3_path, local_path, media_url, thumbnail_url, width, height
                FROM media_items 
                WHERE album_id = ? AND status = 'completed'
                ORDER BY media_type DESC, id ASC
            ", [$album->id]);

        // Transform media items with minimal overhead
        $videos = [];
        $photos = [];

        foreach ($mediaItems as $item) {
            // Build URL with priority: s3_path > local_path > media_url
            $url = ! empty($item->s3_path)
                ? $r2Url.'/'.ltrim($item->s3_path, '/')
                : (! empty($item->local_path)
                    ? (str_starts_with($item->local_path, 'http') ? $item->local_path : $r2Url.'/'.ltrim($item->local_path, '/'))
                    : ($item->media_url ?? ''));

            // Build thumbnail URL
            $thumb = ! empty($item->thumbnail_url)
                ? (str_starts_with($item->thumbnail_url, 'http') ? $item->thumbnail_url : $r2Url.'/'.ltrim($item->thumbnail_url, '/'))
                : '';

            $mediaData = [
                'id' => $item->id,
                'type' => $item->media_type,
                'url' => $url,
                'thumbnail' => $thumb,
                'width' => $item->width,
                'height' => $item->height,
            ];

            if ($item->media_type === 'video') {
                $videos[] = $mediaData;
            } else {
                $photos[] = $mediaData;
            }
        }

        // Get real likes and views counts from interaction tables
        $likesCount = \App\Models\ContentLike::where('likeable_type', Album::class)
            ->where('likeable_id', $album->id)
            ->count();

        $viewsCount = \App\Models\ContentView::where('viewable_type', Album::class)
            ->where('viewable_id', $album->id)
            ->count();

        // Build album response data
        $albumData = [
            'id' => $album->id,
            'album_id' => $album->album_id,
            'user_id' => $album->user_id,
            'title' => $album->title ?: 'Sem título',
            'description' => $album->description,
            'views' => $viewsCount,
            'likes' => $likesCount,
            'created_at' => date('d/m/Y', strtotime($album->created_at)),
            'author' => [
                'name' => $album->author ?? 'Anônimo',
                'avatar' => $authorAvatar,
                'url' => $album->author_url ?? '#',
            ],
            'tags' => $album->tags ? explode(',', $album->tags) : [],
            'status' => $album->is_public ? 'Public' : 'Private',
            'slug' => $album->slug,
        ];

        // SEO: Canonical Redirect (301) if slug is missing or incorrect
        // Ignore for non-GET methods or if slug matches
        $requestSlug = $request->route('slug');
        if ($request->isMethod('get') && ! empty($album->slug) && $requestSlug !== $album->slug) {
            return redirect()->route('album.show', ['id' => $albumId, 'slug' => $album->slug], 301);
        }

        // Cache the response data for 5 minutes
        cache()->put($cacheKey, [
            'id' => $album->id,
            'user_id' => $album->user_id,
            'is_public' => $album->is_public,
            'album' => $albumData,
            'videos' => $videos,
            'photos' => $photos,
        ], 300);

        // Register view asynchronously
        $this->registerViewAsync($album, $request);

        // Get related albums (can be lazy loaded via AJAX for even faster initial load)
        $related = $this->getRelatedAlbumsFast($album->id, $r2Url);

        return Inertia::render('Album/Show', [
            'album' => $albumData,
            'videos' => $videos,
            'photos' => $photos,
            'relatedAlbums' => $related,
            'seo' => [
                'title' => $albumData['title'].' | '.config('app.name'),
                'description' => $albumData['description'] ?? "Veja o álbum {$albumData['title']} de {$albumData['author']['name']} no HubPorn.",
                'keywords' => implode(', ', array_merge($albumData['tags'], ['porn', 'videos', 'fotos'])),
                'image' => $videos[0]['thumbnail'] ?? $photos[0]['url'] ?? null,
            ],
        ]);
    }

    /**
     * Get related albums with optimized query (no slow RAND())
     */
    protected function getRelatedAlbumsFast(int $excludeId, string $r2Url): array
    {
        // Get max album ID for random range selection (much faster than ORDER BY RAND())
        $maxId = DB::connection('mysql_content')
            ->selectOne('SELECT MAX(id) as max_id FROM albums WHERE is_public = 1');

        $maxAlbumId = $maxId->max_id ?? 1000;

        // Generate random IDs and fetch those albums (much faster than RAND())
        $randomIds = [];
        for ($i = 0; $i < 20; $i++) {
            $randomIds[] = rand(1, $maxAlbumId);
        }
        $randomIds = array_unique($randomIds);
        $placeholders = implode(',', array_fill(0, count($randomIds), '?'));

        $related = DB::connection('mysql_content')
            ->select("
                SELECT 
                    a.id, a.album_id, a.title, a.slug, 0 as views, 0 as likes, a.photo_count, a.video_count, a.author,
                    (SELECT COALESCE(m.thumbnail_url, m.s3_path) 
                     FROM media_items m 
                     WHERE m.album_id = a.id AND m.status = 'completed' 
                     ORDER BY FIELD(m.media_type, 'photo', 'video'), m.id 
                     LIMIT 1) as thumb_path,
                    (SELECT m.media_type 
                     FROM media_items m 
                     WHERE m.album_id = a.id AND m.status = 'completed' 
                     ORDER BY FIELD(m.media_type, 'photo', 'video'), m.id 
                     LIMIT 1) as thumb_type,
                    (SELECT m.s3_path 
                     FROM media_items m 
                     WHERE m.album_id = a.id AND m.status = 'completed' AND m.media_type = 'video'
                     LIMIT 1) as video_path
                FROM albums a
                WHERE a.is_public = 1 AND a.id != ? AND a.id IN ({$placeholders})
                LIMIT 8
            ", array_merge([$excludeId], $randomIds), false);

        // Populate with real likes/views
        if (! empty($related)) {
            $relatedIds = array_column($related, 'id');

            $likes = \App\Models\ContentLike::where('likeable_type', \App\Models\Album::class)
                ->whereIn('likeable_id', $relatedIds)
                ->selectRaw('likeable_id, COUNT(*) as count')
                ->groupBy('likeable_id')
                ->pluck('count', 'likeable_id')
                ->toArray();

            $views = \App\Models\ContentView::where('viewable_type', \App\Models\Album::class)
                ->whereIn('viewable_id', $relatedIds)
                ->selectRaw('viewable_id, COUNT(*) as count')
                ->groupBy('viewable_id')
                ->pluck('count', 'viewable_id')
                ->toArray();

            foreach ($related as $item) {
                $item->likes = $likes[$item->id] ?? 0;
                $item->views = $views[$item->id] ?? 0;
            }
        }

        return array_map(function ($a) use ($r2Url) {
            $thumbnail = '/assets/images/placeholder.jpg';
            $thumbIsVideo = false;
            $previewUrl = null;

            if (! empty($a->thumb_path)) {
                $thumbnail = str_starts_with($a->thumb_path, 'http')
                    ? $a->thumb_path
                    : $r2Url.'/'.ltrim($a->thumb_path, '/');
                $thumbIsVideo = $a->thumb_type === 'video' && empty($a->thumbnail_url);
            }

            if (! empty($a->video_path)) {
                $previewUrl = $r2Url.'/'.ltrim($a->video_path, '/');
            }

            return [
                'id' => $a->id,
                'album_id' => $a->album_id,
                'slug' => $a->slug ?? null,
                'title' => $a->title ?: 'Sem título',
                'thumbnail' => $thumbnail,
                'thumb_is_video' => $thumbIsVideo,
                'preview_url' => $previewUrl,
                'views' => $a->views ?? 0,
                'likes' => $a->likes ?? 0,
                'photo_count' => $a->photo_count ?? 0,
                'video_count' => $a->video_count ?? 0,
                'author' => $a->author ?? 'Anônimo',
                'author_avatar' => '/assets/images/default-avatar.jpg',
            ];
        }, $related);
    }

    /**
     * Register view without blocking the response
     */
    /**
     * Register view without blocking the response
     */
    protected function registerViewAsync($album, Request $request): void
    {
        $key = 'viewed_album_'.$album->id;

        if (! $request->session()->has($key)) {
            // Regster view in new content_views table (default DB)
            try {
                \App\Models\ContentView::create([
                    'viewable_type' => \App\Models\Album::class,
                    'viewable_id' => $album->id,
                    'user_id' => $request->user()?->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr($request->userAgent() ?? '', 0, 255),
                    'viewed_at' => now(),
                ]);
            } catch (\Exception $e) {
                // Ignore errors (e.g. unique constraint if user refreshes fast)
            }

            // Also maintain legacy counter for now (optional, but good for backup)
            try {
                DB::connection('mysql_content')->update(
                    'UPDATE albums SET views = views + 1 WHERE id = ?',
                    [$album->id]
                );
            } catch (\Exception $e) {
            }

            $request->session()->put($key, true);
        }
    }

    /**
     * API endpoint for lazy loading related albums
     */
    public function getRelatedApi(string $albumId)
    {
        $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');

        // Get album ID from album_id string
        $album = DB::connection('mysql_content')
            ->selectOne('SELECT id FROM albums WHERE album_id = ? AND is_public = 1 LIMIT 1', [$albumId]);

        if (! $album) {
            return response()->json(['data' => []]);
        }

        // Cache related albums for 10 minutes
        $cacheKey = "related_albums_{$album->id}";
        $related = cache()->remember($cacheKey, 600, function () use ($album, $r2Url) {
            return $this->getRelatedAlbumsFast($album->id, $r2Url);
        });

        return response()->json(['data' => $related]);
    }

    protected function transformMedia($item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->media_type,
            'url' => $this->getMediaUrl($item),
            'thumbnail' => $this->getStorageUrl($item->thumbnail_url),
            'width' => $item->width,
            'height' => $item->height,
        ];
    }

    protected function getRelatedAlbums(Album $album)
    {
        try {
            $related = Album::query()
                ->where('is_public', true)
                ->where('id', '!=', $album->id)
                ->with(['mediaItems' => function ($q) {
                    $q->where('status', 'completed')
                        ->orderByRaw("FIELD(media_type, 'photo', 'video')")
                        ->limit(1);
                }])
                ->inRandomOrder()
                ->limit(8)
                ->get();

            return $related->map(function ($a) {
                // Get thumbnail with fallback
                $thumbnail = '/assets/images/placeholder.jpg';
                $previewUrl = null;
                $thumbIsVideo = false;

                $media = $a->mediaItems->first();
                if ($media) {
                    if (! empty($media->thumbnail_url)) {
                        $thumbnail = $this->getStorageUrl($media->thumbnail_url);
                    } elseif (! empty($media->s3_path)) {
                        $thumbnail = $this->getStorageUrl($media->s3_path);
                        $thumbIsVideo = $media->media_type === 'video';
                    }

                    // Get video preview if available
                    if ($media->media_type === 'video' && ! empty($media->s3_path)) {
                        $previewUrl = $this->getStorageUrl($media->s3_path);
                    }
                }

                return [
                    'id' => $a->id,
                    'album_id' => $a->album_id,
                    'title' => $a->title ?: 'Sem título',
                    'thumbnail' => $thumbnail,
                    'thumb_is_video' => $thumbIsVideo,
                    'preview_url' => $previewUrl,
                    'views' => $a->views ?? 0,
                    'likes' => $a->likes ?? 0,
                    'photo_count' => $a->photo_count ?? 0,
                    'video_count' => $a->video_count ?? 0,
                    'author' => $a->author ?? 'Anônimo',
                    'author_avatar' => '/assets/images/default-avatar.jpg',
                ];
            });
        } catch (\Exception $e) {
            \Log::warning('Failed to get related albums: '.$e->getMessage());

            return collect([]);
        }
    }

    protected function registerView(Album $album, Request $request): void
    {
        $key = 'viewed_album_'.$album->id;

        if (! $request->session()->has($key)) {
            // New interaction table (default DB)
            try {
                \App\Models\ContentView::create([
                    'viewable_type' => \App\Models\Album::class,
                    'viewable_id' => $album->id,
                    'user_id' => $request->user()?->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr($request->userAgent() ?? '', 0, 255),
                    'viewed_at' => now(),
                ]);
            } catch (\Exception $e) {
            }

            // Legacy update (mysql_content)
            try {
                $album->increment('views');
            } catch (\Exception $e) {
            }

            $request->session()->put($key, true);
        }
    }

    protected function getMediaUrl($media): string
    {
        if (! empty($media->s3_path)) {
            return $this->getStorageUrl($media->s3_path);
        }
        if (! empty($media->local_path)) {
            return $this->getStorageUrl($media->local_path);
        }

        return $media->media_url ?? '';
    }

    protected function getStorageUrl(?string $path): string
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

        $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');

        return $r2Url.'/'.ltrim($path, '/');
    }

    protected function getAlbumThumbnail(Album $album): string
    {
        $media = $album->mediaItems()
            ->where('status', 'completed')
            ->orderByRaw("FIELD(media_type, 'photo', 'video')")
            ->first();

        if ($media) {
            if (! empty($media->thumbnail_url)) {
                return $this->getStorageUrl($media->thumbnail_url);
            }
            if (! empty($media->s3_path)) {
                return $this->getStorageUrl($media->s3_path);
            }
        }

        return '/assets/images/placeholder.jpg';
    }

    /**
     * Delete an entire album including all media from R2 and both databases
     */
    public function destroy(Request $request, string $albumId)
    {
        // Configure connections
        $this->configureConnections();

        // Find album by public ID
        $album = DB::connection('mysql_content_write')
            ->table('albums')
            ->where('album_id', $albumId)
            ->first();

        if (! $album) {
            return back()->withErrors(['message' => 'Álbum não encontrado.']);
        }

        // Check ownership
        if ($album->user_id !== $request->user()->id) {
            return back()->withErrors(['message' => 'Você não tem permissão para deletar este álbum.']);
        }

        try {
            // 1. Get all media items
            $mediaItems = DB::connection('mysql_content_write')
                ->table('media_items')
                ->where('album_id', $album->id)
                ->get();

            // 2. Delete files from R2
            foreach ($mediaItems as $media) {
                if (! empty($media->s3_path)) {
                    try {
                        Storage::disk('r2')->delete($media->s3_path);
                        \Log::info("Deleted from R2: {$media->s3_path}");
                    } catch (\Exception $e) {
                        \Log::warning("Failed to delete from R2: {$media->s3_path} - {$e->getMessage()}");
                    }
                }
            }

            // 3. Delete media items from both databases
            DB::connection('mysql_content_write')
                ->table('media_items')
                ->where('album_id', $album->id)
                ->delete();

            try {
                DB::connection('mysql_content_read')
                    ->table('media_items')
                    ->where('album_id', $album->id)
                    ->delete();
            } catch (\Exception $e) {
                \Log::warning("Failed to delete media from read DB: {$e->getMessage()}");
            }

            // 4. Delete album from both databases
            DB::connection('mysql_content_write')
                ->table('albums')
                ->where('id', $album->id)
                ->delete();

            try {
                DB::connection('mysql_content_read')
                    ->table('albums')
                    ->where('id', $album->id)
                    ->delete();
            } catch (\Exception $e) {
                \Log::warning("Failed to delete album from read DB: {$e->getMessage()}");
            }

            \Log::info("Album deleted: {$albumId} with ".count($mediaItems).' media items');

            // 5. Invalidate caches
            cache()->forget("user_stats_{$request->user()->id}");
            cache()->forget("album_show_{$albumId}");

            return redirect()->route('dashboard')
                ->with('success', 'Álbum deletado com sucesso!');

        } catch (\Exception $e) {
            \Log::error('Delete Album Error: '.$e->getMessage());

            return back()->withErrors(['message' => 'Erro ao deletar álbum: '.$e->getMessage()]);
        }
    }

    /**
     * Delete a single media item from R2 and both databases
     */
    public function destroyMedia(Request $request, int $mediaId)
    {
        // Configure connections
        $this->configureConnections();

        // Find media item
        $media = DB::connection('mysql_content_write')
            ->table('media_items')
            ->where('id', $mediaId)
            ->first();

        if (! $media) {
            return back()->withErrors(['message' => 'Mídia não encontrada.']);
        }

        // Get album to check ownership
        $album = DB::connection('mysql_content_write')
            ->table('albums')
            ->where('id', $media->album_id)
            ->first();

        if (! $album || $album->user_id !== $request->user()->id) {
            return back()->withErrors(['message' => 'Você não tem permissão para deletar esta mídia.']);
        }

        try {
            // 1. Delete file from R2
            if (! empty($media->s3_path)) {
                try {
                    Storage::disk('r2')->delete($media->s3_path);
                    \Log::info("Deleted from R2: {$media->s3_path}");
                } catch (\Exception $e) {
                    \Log::warning("Failed to delete from R2: {$media->s3_path} - {$e->getMessage()}");
                }
            }

            // 2. Delete from both databases
            DB::connection('mysql_content_write')
                ->table('media_items')
                ->where('id', $mediaId)
                ->delete();

            try {
                DB::connection('mysql_content_read')
                    ->table('media_items')
                    ->where('id', $mediaId)
                    ->delete();
            } catch (\Exception $e) {
                \Log::warning("Failed to delete media from read DB: {$e->getMessage()}");
            }

            // 3. Update album counts
            $isVideo = $media->media_type === 'video';
            $countField = $isVideo ? 'video_count' : 'photo_count';

            DB::connection('mysql_content_write')
                ->table('albums')
                ->where('id', $album->id)
                ->decrement($countField);

            try {
                DB::connection('mysql_content_read')
                    ->table('albums')
                    ->where('id', $album->id)
                    ->decrement($countField);
            } catch (\Exception $e) {
                \Log::warning("Failed to update counts in read DB: {$e->getMessage()}");
            }

            \Log::info("Media deleted: {$mediaId}");

            return back()->with('success', 'Mídia deletada com sucesso!');

        } catch (\Exception $e) {
            \Log::error('Delete Media Error: '.$e->getMessage());

            return back()->withErrors(['message' => 'Erro ao deletar mídia: '.$e->getMessage()]);
        }
    }

    /**
     * Configure database connections dynamically
     */
    protected function configureConnections(): void
    {
        $baseConfig = config('database.connections.mysql_content');
        unset($baseConfig['read'], $baseConfig['write'], $baseConfig['sticky']);

        if (! config('database.connections.mysql_content_write')) {
            $writeConfig = array_merge($baseConfig, config('database.connections.mysql_content.write') ?? []);
            config(['database.connections.mysql_content_write' => $writeConfig]);
        }

        if (! config('database.connections.mysql_content_read')) {
            $readConfig = array_merge($baseConfig, config('database.connections.mysql_content.read') ?? []);
            config(['database.connections.mysql_content_read' => $readConfig]);
        }
    }
}
