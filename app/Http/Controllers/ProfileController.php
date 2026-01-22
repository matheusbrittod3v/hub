<?php

namespace App\Http\Controllers;

use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show user profile page
     */
    public function show(Request $request, int $userId): Response
    {
        // Configure connections
        $this->configureConnections();

        // Get user from main database
        $user = DB::connection('mysql')
            ->table('users')
            ->where('id', $userId)
            ->first();

        if (!$user) {
            abort(404);
        }

        // Check if viewing own profile
        $isOwnProfile = $request->user()?->id === $user->id;

        // Get albums from content database
        $albums = DB::connection('mysql_content_write')
            ->table('albums')
            ->where('user_id', $userId)
            ->where(function ($query) use ($isOwnProfile) {
                if (!$isOwnProfile) {
                    $query->where('is_public', 1);
                }
            })
            ->orderBy('id', 'desc')
            ->paginate(20);

        // Get thumbnails for albums
        $albumsWithThumbs = collect($albums->items())->map(function ($album) {
            $album->thumbnail = $this->getAlbumThumbnail($album->id);
            $album->thumb_is_video = false;
            return $album;
        });

        // Calculate stats
        $stats = DB::connection('mysql_content_write')
            ->table('albums')
            ->where('user_id', $userId)
            ->selectRaw('COUNT(*) as total_albums, SUM(views) as total_views, SUM(likes) as total_likes')
            ->first();

        return Inertia::render('Profile/Index', [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $this->getStorageUrl($user->avatar_url ?? null) ?: '/assets/images/default-avatar.jpg',
                'cover' => $this->getStorageUrl($user->cover_url ?? null),
                'bio' => $user->bio ?? null,
                'created_at' => $user->created_at,
            ],
            'stats' => [
                'albums' => (int) ($stats->total_albums ?? 0),
                'views' => (int) ($stats->total_views ?? 0),
                'likes' => (int) ($stats->total_likes ?? 0),
            ],
            'albums' => $albumsWithThumbs->map(fn($a) => [
                'id' => $a->id,
                'album_id' => $a->album_id,
                'title' => $a->title ?: 'Sem título',
                'thumbnail' => $a->thumbnail,
                'thumb_is_video' => $a->thumb_is_video,
                'views' => $a->views,
                'likes' => $a->likes,
                'video_count' => $a->video_count,
                'photo_count' => $a->photo_count,
                'is_public' => $a->is_public,
            ]),
            'pagination' => [
                'current_page' => $albums->currentPage(),
                'last_page' => $albums->lastPage(),
                'total' => $albums->total(),
            ],
            'isOwnProfile' => $isOwnProfile,
        ]);
    }

    /**
     * Update user avatar - upload to R2
     */
    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:5120', // 5MB
        ]);

        $user = $request->user();
        $file = $request->file('avatar');

        // Generate path
        $path = 'avatars/' . $user->id . '/' . Str::random(20) . '.' . $file->getClientOriginalExtension();

        // Upload to R2
        Storage::disk('r2')->put($path, file_get_contents($file), 'public');

        // Update user in database
        DB::connection('mysql')
            ->table('users')
            ->where('id', $user->id)
            ->update(['avatar_url' => $path]);

        \Log::info("Avatar updated for user {$user->id}: {$path}");

        return back()->with('success', 'Foto de perfil atualizada!');
    }

    /**
     * Update user cover - upload to R2
     */
    public function updateCover(Request $request)
    {
        $request->validate([
            'cover' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240', // 10MB
        ]);

        $user = $request->user();
        $file = $request->file('cover');

        // Generate path
        $path = 'covers/' . $user->id . '/' . Str::random(20) . '.' . $file->getClientOriginalExtension();

        // Upload to R2
        Storage::disk('r2')->put($path, file_get_contents($file), 'public');

        // Update user in database
        DB::connection('mysql')
            ->table('users')
            ->where('id', $user->id)
            ->update(['cover_url' => $path]);

        \Log::info("Cover updated for user {$user->id}: {$path}");

        return back()->with('success', 'Imagem de capa atualizada!');
    }

    /**
     * Get album thumbnail
     */
    protected function getAlbumThumbnail(int $albumId): string
    {
        // Try to get a photo first
        $media = DB::connection('mysql_content_write')
            ->table('media_items')
            ->where('album_id', $albumId)
            ->where('media_type', 'photo')
            ->where('status', 'completed')
            ->first();

        if ($media) {
            return $this->getStorageUrl($media->s3_path ?? $media->local_path);
        }

        // Try video
        $media = DB::connection('mysql_content_write')
            ->table('media_items')
            ->where('album_id', $albumId)
            ->where('media_type', 'video')
            ->where('status', 'completed')
            ->first();

        if ($media) {
            if (!empty($media->thumbnail_url)) {
                return $this->getStorageUrl($media->thumbnail_url);
            }
            return $this->getStorageUrl($media->s3_path ?? $media->local_path);
        }

        return '/assets/images/placeholder.jpg';
    }

    /**
     * Get storage URL
     */
    protected function getStorageUrl(?string $path): string
    {
        if (empty($path)) return '';
        if (str_starts_with($path, 'http')) return $path;
        if (str_starts_with($path, '/')) return $path;

        $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');
        return $r2Url . '/' . ltrim($path, '/');
    }

    /**
     * Configure database connections dynamically
     */
    protected function configureConnections(): void
    {
        $baseConfig = config('database.connections.mysql_content');
        if (!$baseConfig) return;
        
        unset($baseConfig['read'], $baseConfig['write'], $baseConfig['sticky']);

        if (!config('database.connections.mysql_content_write')) {
            $writeConfig = array_merge($baseConfig, config('database.connections.mysql_content.write') ?? []);
            config(['database.connections.mysql_content_write' => $writeConfig]);
        }

        if (!config('database.connections.mysql_content_read')) {
            $readConfig = array_merge($baseConfig, config('database.connections.mysql_content.read') ?? []);
            config(['database.connections.mysql_content_read' => $readConfig]);
        }
    }
}
