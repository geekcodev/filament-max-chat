<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Models;

use GeekCo\FilamentMaxChat\Enums\ChatMessageDirection;
use GeekCo\FilamentMaxChat\Enums\ChatMessageSender;
use GeekCo\FilamentMaxChat\Models\BotChat;
use GeekCo\FilamentMaxChat\Models\ChatMessage;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChatMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_text_with_text_content(): void
    {
        $message = $this->createMessage('Привет мир');

        $this->assertSame('Привет мир', $message->previewText());
    }

    public function test_preview_text_with_null_text_returns_file_preview(): void
    {
        $message = $this->createMessage(null, ['type' => 'file']);

        $this->assertNotEmpty($message->previewText());
    }

    public function test_preview_text_with_image_attachment(): void
    {
        $message = $this->createMessage(null, ['type' => 'image']);

        $this->assertNotEmpty($message->previewText());
    }

    public function test_preview_text_with_video_attachment(): void
    {
        $message = $this->createMessage(null, ['type' => 'video']);

        $this->assertNotEmpty($message->previewText());
    }

    public function test_preview_text_with_audio_attachment(): void
    {
        $message = $this->createMessage(null, ['type' => 'audio']);

        $this->assertNotEmpty($message->previewText());
    }

    public function test_preview_text_with_empty_text_and_no_attachment(): void
    {
        $message = $this->createMessage('');

        $this->assertNotEmpty($message->previewText());
    }

    public function test_bot_chat_relation(): void
    {
        $chat = BotChat::query()->create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => BotChatStatus::Active,
            'last_activity_at' => now(),
        ]);

        $message = ChatMessage::query()->create([
            'bot_chat_id' => $chat->id,
            'user_id' => 111,
            'chat_id' => 222,
            'direction' => ChatMessageDirection::In,
            'sender_type' => ChatMessageSender::User,
            'text' => 'Test',
        ]);

        $this->assertInstanceOf(BotChat::class, $message->botChat);
        $this->assertSame($chat->id, $message->botChat->id);
    }

    /**
     * @param array{type: string}|null $attachment
     */
    private function createMessage(?string $text, ?array $attachment = null): ChatMessage
    {
        $chat = BotChat::query()->create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => BotChatStatus::Active,
            'last_activity_at' => now(),
        ]);

        return ChatMessage::query()->create([
            'bot_chat_id' => $chat->id,
            'user_id' => 111,
            'chat_id' => 222,
            'direction' => ChatMessageDirection::In,
            'sender_type' => ChatMessageSender::User,
            'text' => $text,
            'attachment' => $attachment,
        ]);
    }
}
