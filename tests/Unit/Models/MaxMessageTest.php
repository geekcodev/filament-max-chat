<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Models;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MaxMessageTest extends TestCase
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

    public function test_max_chat_relation(): void
    {
        $chat = MaxChat::query()->create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'last_activity_at' => now(),
        ]);

        $message = MaxMessage::query()->create([
            'max_chat_id' => $chat->id,
            'user_id' => 111,
            'chat_id' => 222,
            'direction' => MaxMessageDirection::In,
            'sender_type' => MaxMessageSender::User,
            'text' => 'Test',
        ]);

        $this->assertInstanceOf(MaxChat::class, $message->maxChat);
        $this->assertSame($chat->id, $message->maxChat->id);
    }

    /**
     * @param array{type: string}|null $attachment
     */
    private function createMessage(?string $text, ?array $attachment = null): MaxMessage
    {
        $chat = MaxChat::query()->create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'last_activity_at' => now(),
        ]);

        return MaxMessage::query()->create([
            'max_chat_id' => $chat->id,
            'user_id' => 111,
            'chat_id' => 222,
            'direction' => MaxMessageDirection::In,
            'sender_type' => MaxMessageSender::User,
            'text' => $text,
            'attachment' => $attachment,
        ]);
    }
}
