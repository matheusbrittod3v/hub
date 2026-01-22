<?php

namespace App\Http\Controllers;

use App\Models\ContentLike;
use App\Models\ContentView;
use App\Models\ContentReport;
use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InteractionController extends Controller
{
    /**
     * Map of content types to their models.
     */
    protected array $typeMap = [
        'album' => Album::class,
        // Adicione outros tipos conforme necessário
        // 'media' => MediaItem::class,
    ];

    /**
     * Toggle like on a content.
     * POST /api/like
     * Works for both logged-in and anonymous users.
     * Uses session to prevent duplicate likes from same browser.
     */
    public function toggleLike(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:album,media',
            'id' => 'required|integer',
        ]);

        $type = $request->input('type');
        $id = $request->input('id');
        $modelClass = $this->typeMap[$type] ?? null;

        if (!$modelClass) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de conteúdo inválido.',
            ], 400);
        }

        // Get user ID if logged in, otherwise use IP
        $userId = $request->user()?->id;
        $ipAddress = $request->ip();
        
        // Session key for this content
        $sessionKey = "liked_{$type}_{$id}";
        $hasLikedInSession = $request->session()->has($sessionKey);

        // Check if already liked in session (for anonymous users)
        if (!$userId && $hasLikedInSession) {
            // User already liked in this session, so we remove the like
            $request->session()->forget($sessionKey);
            
            // Try to remove the like from database
            ContentLike::where('ip_address', $ipAddress)
                ->whereNull('user_id')
                ->where('likeable_type', $modelClass)
                ->where('likeable_id', $id)
                ->delete();
            
            $newCount = ContentLike::countLikes($modelClass, $id);
            
            return response()->json([
                'success' => true,
                'liked' => false,
                'count' => $newCount,
                'message' => 'Curtida removida.',
            ]);
        }
        
        // Rate limiting for anonymous likes - max 50 likes per hour per IP
        if (!$userId) {
            $recentLikes = ContentLike::where('ip_address', $ipAddress)
                ->whereNull('user_id')
                ->where('created_at', '>=', now()->subHour())
                ->count();
            
            if ($recentLikes >= 50) {
                return response()->json([
                    'success' => false,
                    'message' => 'Muitas curtidas em pouco tempo. Tente novamente mais tarde.',
                ], 429);
            }
        }

        try {
            if ($userId) {
                // For logged-in users, use regular toggle
                $liked = ContentLike::toggleLike($userId, $ipAddress, $modelClass, $id);
            } else {
                // For anonymous users, check if already liked by IP
                $existingLike = ContentLike::where('ip_address', $ipAddress)
                    ->whereNull('user_id')
                    ->where('likeable_type', $modelClass)
                    ->where('likeable_id', $id)
                    ->first();
                
                if ($existingLike) {
                    // Remove the like
                    $existingLike->delete();
                    $request->session()->forget($sessionKey);
                    $liked = false;
                } else {
                    // Create new like
                    ContentLike::create([
                        'user_id' => null,
                        'ip_address' => $ipAddress,
                        'likeable_type' => $modelClass,
                        'likeable_id' => $id,
                    ]);
                    $request->session()->put($sessionKey, true);
                    $liked = true;
                }
            }
            
            $newCount = ContentLike::countLikes($modelClass, $id);

            return response()->json([
                'success' => true,
                'liked' => $liked,
                'count' => $newCount,
                'message' => $liked ? 'Curtido!' : 'Curtida removida.',
            ]);
        } catch (\Exception $e) {
            Log::error('Like toggle error: ' . $e->getMessage(), [
                'type' => $type,
                'id' => $id,
                'user_id' => $userId,
                'ip' => $ipAddress,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar a curtida.',
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Check if user/IP has liked a content.
     * GET /api/like/check
     */
    public function checkLike(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:album,media',
            'id' => 'required|integer',
        ]);

        $type = $request->input('type');
        $id = $request->input('id');
        $modelClass = $this->typeMap[$type] ?? null;

        if (!$modelClass) {
            return response()->json([
                'liked' => false,
                'count' => 0,
            ]);
        }

        $userId = $request->user()?->id;
        $ipAddress = $request->ip();
        
        // Check session first for anonymous users
        $sessionKey = "liked_{$type}_{$id}";
        
        if (!$userId && $request->session()->has($sessionKey)) {
            // User liked in this session
            $count = ContentLike::countLikes($modelClass, $id);
            return response()->json([
                'liked' => true,
                'count' => $count,
            ]);
        }

        $liked = ContentLike::hasLiked($userId, $ipAddress, $modelClass, $id);
        $count = ContentLike::countLikes($modelClass, $id);
        
        // Sync session with database for anonymous users
        if (!$userId && $liked) {
            $request->session()->put($sessionKey, true);
        }

        return response()->json([
            'liked' => $liked,
            'count' => $count,
        ]);
    }

    /**
     * Register a view on a content.
     * POST /api/view
     */
    public function registerView(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:album,media',
            'id' => 'required|integer',
        ]);

        $type = $request->input('type');
        $id = $request->input('id');
        $modelClass = $this->typeMap[$type] ?? null;

        if (!$modelClass) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de conteúdo inválido.',
            ], 400);
        }

        $userId = $request->user()?->id;
        $registered = ContentView::registerView($modelClass, $id, $userId, $request);

        return response()->json([
            'success' => true,
            'counted' => $registered,
        ]);
    }

    /**
     * Submit a report.
     * POST /api/report
     */
    public function submitReport(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:album,media,user',
            'id' => 'required|integer',
            'reason' => 'required|string|in:' . implode(',', array_keys(ContentReport::REASONS)),
            'description' => 'nullable|string|max:2000',
        ]);

        $type = $request->input('type');
        $id = $request->input('id');
        $reason = $request->input('reason');
        $description = $request->input('description');

        // Mapeamento de tipos
        $typeMap = $this->typeMap;
        $typeMap['user'] = \App\Models\User::class;
        $modelClass = $typeMap[$type] ?? null;

        if (!$modelClass) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de conteúdo inválido.',
            ], 400);
        }

        // Rate limiting: máximo de 5 reports por hora por IP
        $ip = $request->ip();
        $recentReports = ContentReport::where('ip_address', $ip)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentReports >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'Você enviou muitas denúncias recentemente. Tente novamente mais tarde.',
            ], 429);
        }

        // Verificar se já reportou este conteúdo
        $userId = $request->user()?->id;
        $existingReport = ContentReport::where('reportable_type', $modelClass)
            ->where('reportable_id', $id)
            ->where(function ($q) use ($userId, $ip) {
                if ($userId) {
                    $q->where('reporter_id', $userId);
                } else {
                    $q->where('ip_address', $ip);
                }
            })
            ->where('status', 'pending')
            ->first();

        if ($existingReport) {
            return response()->json([
                'success' => false,
                'message' => 'Você já denunciou este conteúdo. Sua denúncia está em análise.',
            ], 400);
        }

        try {
            $report = ContentReport::createReport(
                $modelClass,
                $id,
                $reason,
                $description,
                $userId,
                $request
            );

            return response()->json([
                'success' => true,
                'message' => 'Denúncia enviada com sucesso. Iremos analisar em breve.',
                'report_id' => $report->id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao enviar denúncia.',
            ], 500);
        }
    }

    /**
     * Get report reasons for a dropdown.
     * GET /api/report/reasons
     */
    public function getReportReasons()
    {
        return response()->json([
            'reasons' => ContentReport::REASONS,
        ]);
    }

    /**
     * Get interaction stats for a content.
     * GET /api/stats
     */
    public function getStats(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:album,media',
            'id' => 'required|integer',
        ]);

        $type = $request->input('type');
        $id = $request->input('id');
        $modelClass = $this->typeMap[$type] ?? null;

        if (!$modelClass) {
            return response()->json([
                'likes' => 0,
                'views' => 0,
                'userLiked' => false,
            ]);
        }

        $userId = $request->user()?->id;
        $ipAddress = $request->ip();
        
        // Check session for anonymous users
        $sessionKey = "liked_{$type}_{$id}";
        $likedInSession = !$userId && $request->session()->has($sessionKey);

        return response()->json([
            'likes' => ContentLike::countLikes($modelClass, $id),
            'views' => ContentView::countViews($modelClass, $id),
            'userLiked' => $likedInSession || ContentLike::hasLiked($userId, $ipAddress, $modelClass, $id),
        ]);
    }
}
