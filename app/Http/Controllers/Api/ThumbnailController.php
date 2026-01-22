<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Services\ThumbnailGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ThumbnailController extends Controller
{
    protected $generator;

    public function __construct(ThumbnailGeneratorService $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Try to generate a thumbnail for the album
     */
    public function generate(Album $album)
    {
        // Find best candidate media (video wins over photo for cover usually, or vice versa depending on logic)
        // Legacy priorities: Media without thumb
        
        $media = $album->mediaItems()
            ->where('status', 'completed')
            ->orderByRaw("FIELD(media_type, 'photo', 'video')") // Photos first
            ->first();

        if (!$media) {
            return response()->json(['error' => 'No media found'], 404);
        }

        // Check if it already has a thumb (maybe updated recently by another process)
        if (!empty($media->thumbnail_url)) {
            return response()->json([
                'thumbnail_url' => $this->getStorageUrl($media->thumbnail_url)
            ]);
        }

        // Attempt generation
        try {
            $key = $this->generator->generateForMedia($media);
            
            if ($key) {
                return response()->json([
                    'thumbnail_url' => $this->getStorageUrl($key)
                ]);
            }
            
            return response()->json(['error' => 'Generation failed'], 500);

        } catch (\Exception $e) {
            Log::error("[ThumbnailController] Error: " . $e->getMessage());
            return response()->json(['error' => 'Server error'], 500);
        }
    }

    protected function getStorageUrl(?string $path): string 
    {
        if (empty($path)) return '';
        if (str_starts_with($path, 'http')) return $path;
        if (str_starts_with($path, '/')) return $path;
        
        $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');
        return $r2Url . '/' . ltrim($path, '/');
    }
}
