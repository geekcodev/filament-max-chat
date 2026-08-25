<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Models;

use GeekCo\FilamentMaxChat\Enums\ChatMessageDirection;
use GeekCo\FilamentMaxChat\Enums\ChatMessageSender;
use GeekCo\FilamentMaxChat\Models\BotChat;
use GeekCo\FilamentMaxChat\Models\ChatMessage;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BotChatTest extends TestCase
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
        $this->createMessage($chat, ChatMessageDirection::In, 'First');
        $this->createMessage($chat, ChatMessageDirection::In, 'Second');

        $this->assertSame(2, $chat->unreadCount());
    }

    public function test_unread_count_excludes_read_messages(): void
    {
        $chat = $this->createChat();
        $message = $this->createMessage($chat, ChatMessageDirection::In, 'Read');

        ChatMessage::query()->where('id', $message->id)->update(['read_at' => now()]);

        $this->assertSame(0, $chat->unreadCount());
    }

    public function test_unread_count_excludes_outgoing_messages(): void
    {
        $chat = $this->createChat();
        $this->createMessage($chat, ChatMessageDirection::In, 'Incoming');
        $this->createMessage($chat, ChatMessageDirection::Out, 'Outgoing');

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

        $chat = BotChat::query()->create([
            'user_id' => 999,
            'chat_id' => 333,
            'status' => BotChatStatus::Active,
            'last_activity_at' => now(),
        ]);

        $this->assertStringContainsString('999', $chat->conversationName());
    }

    private function createChat(): BotChat
    {
        MaxUser::query()->updateOrCreate(
            ['user_id' => 111],
            ['first_name' => 'Иван', 'last_name' => 'Петров'],
        );

        return BotChat::query()->create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => BotChatStatus::Active,
            'last_activity_at' => now(),
        ]);
    }

    private function createMessage(BotChat $chat, ChatMessageDirection $direction, ?string $text): ChatMessage
    {
        return ChatMessage::query()->create([
            'bot_chat_id' => $chat->id,
            'user_id' => $chat->user_id,
            'chat_id' => $chat->chat_id,
            'direction' => $direction,
            'sender_type' => ChatMessageSender::User,
            'text' => $text,
        ]);
    }
}
