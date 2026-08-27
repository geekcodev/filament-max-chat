<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Http\Controllers;

use GeekCo\FilamentMaxChat\Enums\ChatMessageDirection;
use GeekCo\FilamentMaxChat\Models\ChatMessage;
use GeekCo\FilamentMaxChat\Services\ChatMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the total unread count and the latest unread incoming chat
 * (feed for the global notification HTTP-poll outside the chat page).
 */
class UnreadCountController
{
    public function __invoke(Request $request, ChatMessageService $service): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        if ($user->can((string) config()->string('filament-max-chat.permissions.view')) !== true) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        $latestBotChatId = ChatMessage::query()
            ->where('direction', ChatMessageDirection::In)
            ->whereNull('read_at')
            ->latest('id')
            ->value('bot_chat_id');

        return response()->json([
            'unread_count' => $service->totalUnreadCount(),
            'latest_bot_chat_id' => is_int($latestBotChatId) ? $latestBotChatId : null,
        ]);
    }
}
