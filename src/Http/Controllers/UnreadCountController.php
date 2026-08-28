<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Http\Controllers;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the total unread count and the latest unread incoming chat
 * (feed for the global notification HTTP-poll outside the chat page).
 */
class UnreadCountController
{
    public function __invoke(Request $request, MaxMessageService $service): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        if ($user->can((string) config()->string('filament-max-chat.permissions.view')) !== true) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        $latestMaxChatId = MaxMessage::query()
            ->where('direction', MaxMessageDirection::In)
            ->whereNull('read_at')
            ->latest('id')
            ->value('max_chat_id');

        return response()->json([
            'unread_count' => $service->totalUnreadCount(),
            'latest_max_chat_id' => is_int($latestMaxChatId) ? $latestMaxChatId : null,
        ]);
    }
}
