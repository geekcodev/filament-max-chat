<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Events;

use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MaxMessageCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public MaxMessage $message)
    {
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
        return 'chat-message.created';
    }

    /** @return array{id: int, max_chat_id: int, unread_count: int} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'max_chat_id' => $this->message->max_chat_id,
            'unread_count' => app(MaxMessageService::class)->totalUnreadCount(),
        ];
    }
}
