<?php

namespace App\Services;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ThumbnailGeneratorService
{
    protected const THUMBNAIL_WIDTH = 480;
    protected const THUMBNAIL_HEIGHT = 270;
    protected const THUMBNAIL_QUALITY = 85;

    /**
     * Generate thumbnail for a specific media item
     */
    public function generateForMedia(MediaItem $media): ?string
    {
        $mediaUrl = $this->getMediaSourceUrl($media);

        if (!$mediaUrl) {
            Log::warning("[ThumbnailGenerator] Media #{$media->id} has no valid source URL.");
            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';

        try {
            $success = false;
            
            if ($media->media_type === 'video') {
                $success = $this->extractVideoFrame($mediaUrl, $tempPath);
            } else {
                $success = $this->resizeImage($mediaUrl, $tempPath);
            }

            if (!$success || !file_exists($tempPath) || filesize($tempPath) === 0) {
                Log::error("[ThumbnailGenerator] Failed to generate local thumbnail for media #{$media->id}");
                return null;
            }

            // Upload to R2
            $filename = 'thumb_' . uniqid() . '_' . time() . '.jpg';
            $r2Key = 'thumbnails/' . ($media->album->album_id ?? 'unknown') . '/' . $filename;
            
            // Read file content
            $content = file_get_contents($tempPath);
            
            // Upload using Laravel Storage (configured for R2 in 'r2' disk)
            Storage::disk('r2')->put($r2Key, $content, 'public');

            // Construct public URL or Key
            $publicUrl = $r2Key; // We store the key, controller handles full URL

            // Update media item
            $media->thumbnail_url = $publicUrl;
            $media->save();

            Log::info("[ThumbnailGenerator] Generated thumbnail for media #{$media->id}: $r2Key");

            return $publicUrl;

        } catch (\Exception $e) {
            Log::error("[ThumbnailGenerator] Exception for media #{$media->id}: " . $e->getMessage());
            return null;
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    protected function getMediaSourceUrl(MediaItem $media): ?string
    {
        // Check R2 (s3_path)
        if (!empty($media->s3_path)) {
            // If it's a full URL, use it
            if (str_starts_with($media->s3_path, 'http')) {
                return $media->s3_path;
            }
            // If key, build URL
            $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');
            return $r2Url . '/' . ltrim($media->s3_path, '/');
        }

        // Check Local
        if (!empty($media->local_path)) {
            if (str_starts_with($media->local_path, 'http')) {
                return $media->local_path;
            }
            return config('app.url') . '/' . ltrim($media->local_path, '/');
        }

        return $media->media_url ?? null;
    }

    protected function extractVideoFrame(string $videoUrl, string $outputPath): bool
    {
        $ffmpeg = env('FFMPEG_PATH', 'ffmpeg'); // Default to 'ffmpeg' in PATH
        $timeOffset = '00:00:01';

        // Command to extract frame
        $cmd = sprintf(
            '%s -ss %s -i "%s" -vframes 1 -vf "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2" -q:v 2 -y "%s" 2>&1',
            $ffmpeg,
            $timeOffset,
            $videoUrl,
            self::THUMBNAIL_WIDTH,
            self::THUMBNAIL_HEIGHT,
            self::THUMBNAIL_WIDTH,
            self::THUMBNAIL_HEIGHT,
            $outputPath
        );

        exec($cmd, $output, $returnCode);

        // Retry at 0s if failed
        if ($returnCode !== 0 || !file_exists($outputPath)) {
            $cmd = sprintf(
                '%s -i "%s" -vframes 1 -vf "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2" -q:v 2 -y "%s" 2>&1',
                $ffmpeg,
                $videoUrl,
                self::THUMBNAIL_WIDTH,
                self::THUMBNAIL_HEIGHT,
                self::THUMBNAIL_WIDTH,
                self::THUMBNAIL_HEIGHT,
                $outputPath
            );
            exec($cmd, $output, $returnCode);
        }

        return $returnCode === 0 && file_exists($outputPath);
    }

    protected function resizeImage(string $imageUrl, string $outputPath): bool
    {
        // Simple download and resize logic 
        // For simplicity, we'll try to use GD like the legacy script
        try {
            $content = file_get_contents($imageUrl);
            if (!$content) return false;

            $srcImage = imagecreatefromstring($content);
            if (!$srcImage) return false;

            $width = imagesx($srcImage);
            $height = imagesy($srcImage);

            $ratio = min(self::THUMBNAIL_WIDTH / $width, self::THUMBNAIL_HEIGHT / $height);
            $newWidth = (int)($width * $ratio);
            $newHeight = (int)($height * $ratio);

            $dstImage = imagecreatetruecolor(self::THUMBNAIL_WIDTH, self::THUMBNAIL_HEIGHT);
            $black = imagecolorallocate($dstImage, 0, 0, 0);
            imagefill($dstImage, 0, 0, $black);

            $x = (self::THUMBNAIL_WIDTH - $newWidth) / 2;
            $y = (self::THUMBNAIL_HEIGHT - $newHeight) / 2;

            imagecopyresampled(
                $dstImage, $srcImage,
                $x, $y, 0, 0,
                $newWidth, $newHeight,
                $width, $height
            );

            imagejpeg($dstImage, $outputPath, self::THUMBNAIL_QUALITY);
            
            imagedestroy($srcImage);
            imagedestroy($dstImage);

            return file_exists($outputPath);
        } catch (\Exception $e) {
            Log::error("[ThumbnailGenerator] Image resize error: " . $e->getMessage());
            return false;
        }
    }
}
