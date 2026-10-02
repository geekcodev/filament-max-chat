<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Models;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Tests\Support\MakesChats;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MaxChatTest extends TestCase
{
    use MakesChats;
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

    public function test_display_name_with_interlocutor(): void
    {
        $chat = $this->createChat();

        $this->assertSame('Иван Петров', $chat->displayName());
    }

    public function test_display_name_prefers_registry_name_over_first_and_last(): void
    {
        $chat = $this->makeChatWithUser(222, 111, ['name' => 'Иван Петров (профиль MAX)']);

        $this->assertSame(
            'Иван Петров (профиль MAX)',
            $chat->fresh('chatUsers.maxUser')?->displayName(),
        );
    }

    public function test_display_name_falls_back_to_chat_id_without_users(): void
    {
        $chat = $this->makeChat(333);

        $this->assertSame('chat 333', $chat->displayName());
    }

    public function test_interlocutor_skips_bot_added_to_group(): void
    {
        $chat = $this->makeChat(333, ['chat_type' => ChatType::Chat]);
        $this->makeRegistryUser(1, ['is_bot' => true, 'first_name' => 'Бот', 'last_name' => null]);
        $this->makeRegistryUser(2, ['first_name' => 'Мария', 'last_name' => 'Сидорова']);
        $this->linkChatUser(333, 1);
        $this->linkChatUser(333, 2);

        $interlocutor = $chat->fresh('chatUsers.maxUser')?->interlocutor();

        $this->assertNotNull($interlocutor);
        $this->assertSame(2, $interlocutor->user_id);
    }

    public function test_display_name_uses_chat_title_for_group(): void
    {
        $chat = $this->makeChat(333, ['chat_type' => ChatType::Chat, 'title' => 'Проект И2ТЕХ']);

        $this->assertSame('Проект И2ТЕХ', $chat->displayName());
    }

    private function createChat(): MaxChat
    {
        return $this->makeChatWithUser(222, 111);
    }

    private function createMessage(MaxChat $chat, MaxMessageDirection $direction, ?string $text): MaxMessage
    {
        return MaxMessage::query()->create([
            'max_chat_id' => $chat->chat_id,
            'user_id' => $chat->interlocutorId(),
            'chat_id' => $chat->chat_id,
            'direction' => $direction,
            'sender_type' => MaxMessageSender::User,
            'text' => $text,
        ]);
    }
}
