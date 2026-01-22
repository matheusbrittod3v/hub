<?php

namespace App\Http\Controllers;

use App\Models\ChatBlock;
use App\Models\ChatMessage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $room = $request->get('room', 'geral');

        $messages = ChatMessage::with('user:id,name,avatar_url')
            ->where('room', $room)
            ->where('reports_count', '<', 5)
            ->latest()
            ->limit(50)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'messages' => $messages,
            'is_blocked' => $this->isIpBlocked($request->ip()),
        ]);
    }

    public function store(Request $request)
    {
        $ip = $request->ip();

        // 1. Check if blocked
        if ($block = $this->isIpBlocked($ip)) {
            return response()->json([
                'error' => __('messages.chat.blocked_until', ['time' => $block->blocked_until->format('d/m H:i')]),
            ], 403);
        }

        // 2. Check rate limit (1 min)
        $lastMessage = ChatMessage::where('ip_address', $ip)
            ->latest()
            ->first();

        if ($lastMessage && $lastMessage->created_at->addMinute()->isFuture()) {
            $secondsRemaining = $lastMessage->created_at->addMinute()->diffInSeconds(now());

            return response()->json([
                'error' => __('messages.chat.wait_seconds', ['seconds' => $secondsRemaining]),
            ], 429);
        }

        $user = Auth::user();
        $isGuest = ! $user;

        $request->validate([
            'message' => 'nullable|string|max:1000',
            'room' => 'required|string|in:geral,vazadas',
            'type' => 'nullable|string|in:text,image,video,bbcode',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,gif,mp4,webm|max:20480', // 20MB max
            'guest_nickname' => 'nullable|string|max:20',
        ]);

        if (!trim($request->message ?? '') && !$request->hasFile('file')) {
            return response()->json([
                'error' => 'Mensagem ou arquivo é obrigatório.',
            ], 422);
        }

        $type = $request->get('type', 'text');
        $meta = null;

        // Guest Restrictions
        if ($isGuest) {
            $type = 'text'; // Only text for guests
            $nickname = $request->get('guest_nickname') ?: 'Guest_'.Str::random(5);

            // Ensure Guest_ prefix even if spoofed
            if (! Str::startsWith($nickname, 'Guest_')) {
                $nickname = 'Guest_'.$nickname;
            }
        } else {
            $nickname = $user->name;

            // Handle Media if Logged in
            if ($request->hasFile('file') && ($type === 'image' || $type === 'video')) {
                $file = $request->file('file');
                $extension = $file->getClientOriginalExtension();
                $path = 'chat_media/'.date('Y/m').'/'.Str::random(20).'.'.$extension;

                // Upload to R2
                Storage::disk('r2')->put($path, file_get_contents($file), 'public');

                // Build R2 URL
                $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');
                $meta = [
                    'url' => $r2Url.'/'.$path,
                    'filename' => $file->getClientOriginalName(),
                ];
            }
        }

        $message = ChatMessage::create([
            'user_id' => $user?->id,
            'room' => $request->room,
            'nickname' => $nickname,
            'message' => $request->message,
            'type' => $type,
            'meta' => $meta,
            'ip_address' => $ip,
        ]);

        return response()->json($message->load('user:id,name,avatar_url'));
    }

    public function report(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:100',
        ]);

        $chatMessage->increment('reports_count');

        if ($chatMessage->reports_count >= 5) {
            $ip = $chatMessage->ip_address;

            $block = ChatBlock::where('ip_address', $ip)->first();

            if (! $block) {
                ChatBlock::create([
                    'ip_address' => $ip,
                    'blocked_until' => now()->addHour(),
                    'reason' => $request->reason,
                    'reports_accumulator' => 1,
                ]);
            } else {
                // Accumulate block time: 1h additional for each block iteration
                $block->increment('reports_accumulator');
                $hoursToAdd = $block->reports_accumulator;

                $currentBlockEnd = Carbon::parse($block->blocked_until);
                $baseTime = $currentBlockEnd->isFuture() ? $currentBlockEnd : now();

                $newBlockedUntil = $baseTime->addHours($hoursToAdd);

                $block->update([
                    'blocked_until' => $newBlockedUntil,
                    'reason' => $request->reason,
                ]);
            }
        }

        return response()->json(['success' => true]);
    }

    private function isIpBlocked($ip)
    {
        return ChatBlock::where('ip_address', $ip)
            ->where('blocked_until', '>', now())
            ->first();
    }
}
