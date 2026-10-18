<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Events;

use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Диалог удалён в MAX (`dialog_removed`): строка реестра помечена статусом
 * Removed, история и вложения сохранены. Событие нужно открытой странице, чтобы
 * диалог исчез из списка и закрылась лента, если он был выбран, не дожидаясь
 * очередного `wire:poll`.
 */
class MaxChatRemoved implements ShouldBroadcast
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
        return 'chat-removed';
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
