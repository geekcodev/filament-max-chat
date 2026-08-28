<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Models;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MaxChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_unread_count_returns_zero_for_empty_chat(): void
    {
        $chat = $this->createChat();

        $this->assertSame(0, $chat->unreadCount());
    }

    public function test_unread_count_counts_incoming_unread_messages(): void
    {
        $chat = $this->createChat();
        $this->createMessage($chat, MaxMessageDirection::In, 'First');
        $this->createMessage($chat, MaxMessageDirection::In, 'Second');

        $this->assertSame(2, $chat->unreadCount());
    }

    public function test_unread_count_excludes_read_messages(): void
    {
        $chat = $this->createChat();
        $message = $this->createMessage($chat, MaxMessageDirection::In, 'Read');

        MaxMessage::query()->where('id', $message->id)->update(['read_at' => now()]);

        $this->assertSame(0, $chat->unreadCount());
    }

    public function test_unread_count_excludes_outgoing_messages(): void
    {
        $chat = $this->createChat();
        $this->createMessage($chat, MaxMessageDirection::In, 'Incoming');
        $this->createMessage($chat, MaxMessageDirection::Out, 'Outgoing');

        $this->assertSame(1, $chat->unreadCount());
    }

    public function test_conversation_name_with_user(): void
    {
        $chat = $this->createChat();

        $this->assertSame('Иван Петров', $chat->conversationName());
    }

    public function test_conversation_name_fallback_without_user(): void
    {
        MaxUser::query()->where('user_id', 999)->delete();

        $chat = MaxChat::query()->create([
            'user_id' => 999,
            'chat_id' => 333,
            'status' => MaxChatStatus::Active,
            'last_activity_at' => now(),
        ]);

        $this->assertStringContainsString('999', $chat->conversationName());
    }

    private function createChat(): MaxChat
    {
        MaxUser::query()->updateOrCreate(
            ['user_id' => 111],
            ['first_name' => 'Иван', 'last_name' => 'Петров'],
        );

        return MaxChat::query()->create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'last_activity_at' => now(),
        ]);
    }

    private function createMessage(MaxChat $chat, MaxMessageDirection $direction, ?string $text): MaxMessage
    {
        return MaxMessage::query()->create([
            'max_chat_id' => $chat->id,
            'user_id' => $chat->user_id,
            'chat_id' => $chat->chat_id,
            'direction' => $direction,
            'sender_type' => MaxMessageSender::User,
            'text' => $text,
        ]);
    }
}
