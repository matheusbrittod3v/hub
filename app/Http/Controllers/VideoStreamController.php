<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;

class VideoStreamController extends Controller
{
    /**
     * Stream video with Range Request support for efficient seeking
     * 
     * @param Request $request
     * @param string $path The path to the video file (base64 encoded or direct path)
     */
    public function stream(Request $request, string $path)
    {
        // Decode path if base64 encoded
        $decodedPath = base64_decode($path, true);
        $videoPath = $decodedPath ?: $path;
        
        // Security: Sanitize path to prevent directory traversal
        $videoPath = str_replace(['../', '..\\'], '', $videoPath);
        
        // Check if file exists in R2/S3
        $disk = Storage::disk('r2');
        
        if (!$disk->exists($videoPath)) {
            abort(404, 'Video not found');
        }

        // Get file info
        $fileSize = $disk->size($videoPath);
        $mimeType = $disk->mimeType($videoPath) ?: 'video/mp4';
        
        // Get file extension for fallback
        $extension = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'm3u8' => 'application/x-mpegURL',
            'ts' => 'video/MP2T',
        ];
        
        if (isset($mimeTypes[$extension])) {
            $mimeType = $mimeTypes[$extension];
        }

        // Parse Range header
        $range = $request->header('Range');
        $start = 0;
        $end = $fileSize - 1;
        $length = $fileSize;

        if ($range) {
            // Parse range: bytes=start-end
            preg_match('/bytes=(\d*)-(\d*)/', $range, $matches);
            
            if (!empty($matches[1])) {
                $start = intval($matches[1]);
            }
            
            if (!empty($matches[2])) {
                $end = intval($matches[2]);
            }
            
            // Ensure end is not greater than file size
            $end = min($end, $fileSize - 1);
            $length = $end - $start + 1;
            
            // Return 206 Partial Content
            $statusCode = 206;
        } else {
            $statusCode = 200;
        }

        // Build headers
        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=604800', // Cache for 1 week
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Range',
            'Access-Control-Expose-Headers' => 'Content-Length, Content-Range, Accept-Ranges',
        ];

        if ($statusCode === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
        }

        // Stream the file
        return new StreamedResponse(function () use ($disk, $videoPath, $start, $length) {
            $stream = $disk->readStream($videoPath);
            
            if (!$stream) {
                return;
            }

            // Seek to start position
            if ($start > 0) {
                fseek($stream, $start);
            }

            // Output in chunks
            $chunkSize = 1024 * 1024; // 1MB chunks
            $bytesRemaining = $length;

            while ($bytesRemaining > 0 && !feof($stream)) {
                $readSize = min($chunkSize, $bytesRemaining);
                $data = fread($stream, $readSize);
                
                if ($data === false) {
                    break;
                }
                
                echo $data;
                flush();
                
                $bytesRemaining -= strlen($data);
            }

            fclose($stream);
        }, $statusCode, $headers);
    }

    /**
     * Generate a signed URL for direct R2/CDN access (more performant)
     * 
     * @param Request $request
     */
    public function getSignedUrl(Request $request)
    {
        $path = $request->input('path');
        
        if (!$path) {
            return response()->json(['error' => 'Path is required'], 400);
        }

        // Sanitize path
        $path = str_replace(['../', '..\\'], '', $path);

        // Check if file exists
        $disk = Storage::disk('r2');
        
        if (!$disk->exists($path)) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        // Generate temporary URL (recommended for better performance)
        try {
            // If using AWS S3 or compatible (like R2), generate a presigned URL
            $url = $disk->temporaryUrl($path, now()->addHours(2));
            
            return response()->json([
                'url' => $url,
                'expires_at' => now()->addHours(2)->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            // Fallback to direct R2 URL if temporary URLs are not supported
            $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');
            $directUrl = $r2Url . '/' . ltrim($path, '/');
            
            return response()->json([
                'url' => $directUrl,
                'direct' => true,
            ]);
        }
    }

    /**
     * Get video metadata (duration, dimensions, etc.)
     */
    public function metadata(Request $request, string $path)
    {
        $decodedPath = base64_decode($path, true);
        $videoPath = $decodedPath ?: $path;
        $videoPath = str_replace(['../', '..\\'], '', $videoPath);
        
        $disk = Storage::disk('r2');
        
        if (!$disk->exists($videoPath)) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        $size = $disk->size($videoPath);
        $mimeType = $disk->mimeType($videoPath) ?: 'video/mp4';
        $lastModified = $disk->lastModified($videoPath);

        return response()->json([
            'size' => $size,
            'size_formatted' => $this->formatBytes($size),
            'mime_type' => $mimeType,
            'last_modified' => date('c', $lastModified),
        ]);
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Handle preflight CORS requests
     */
    public function options()
    {
        return response('', 204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Range, Accept-Encoding',
            'Access-Control-Max-Age' => '86400',
        ]);
    }
}
