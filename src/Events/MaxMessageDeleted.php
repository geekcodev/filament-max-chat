<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Events;

use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class MaxMessageDeleted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $maxChatId,
        public ?string $messageId,
        public int $messageRowId,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel((string) config()->string('filament-max-chat.broadcast_channel')),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat-message.deleted';
    }

    /** @return array{id: int, max_chat_id: int, message_id: string|null, unread_count: int} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->messageRowId,
            'max_chat_id' => $this->maxChatId,
            'message_id' => $this->messageId,
            'unread_count' => app(MaxMessageService::class)->totalUnreadCount(),
        ];
    }
}
