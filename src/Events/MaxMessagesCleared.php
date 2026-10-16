<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Events;

use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * История диалога очищена в MAX (`dialog_cleared`). Событие несёт только
 * идентификатор чата: к моменту доставки строк истории уже нет, а открытой ленте
 * нужно не поправить одну строку, а перечитать содержимое целиком.
 */
class MaxMessagesCleared implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $maxChatId,
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
        return 'chat-message.cleared';
    }

    /** @return array{max_chat_id: int, unread_count: int} */
    public function broadcastWith(): array
    {
        return [
            'max_chat_id' => $this->maxChatId,
            'unread_count' => app(MaxMessageService::class)->totalUnreadCount(),
        ];
    }
}
