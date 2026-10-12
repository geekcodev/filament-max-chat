<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Models;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Tests\Support\MakesChats;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MaxMessageTest extends TestCase
{
    use MakesChats;
    use RefreshDatabase;

    public function test_preview_text_with_text_content(): void
    {
        $message = $this->createMessage('Привет мир');

        $this->assertSame('Привет мир', $message->previewText());
    }

    public function test_preview_text_with_null_text_returns_file_preview(): void
    {
        $message = $this->createMessage(null, [['type' => 'file']]);

        $this->assertNotEmpty($message->previewText());
    }

    public function test_preview_text_with_image_attachment(): void
    {
        $message = $this->createMessage(null, [['type' => 'image']]);

        $this->assertNotEmpty($message->previewText());
    }

    public function test_preview_text_with_video_attachment(): void
    {
        $message = $this->createMessage(null, [['type' => 'video']]);

        $this->assertNotEmpty($message->previewText());
    }

    public function test_preview_text_with_audio_attachment(): void
    {
        $message = $this->createMessage(null, [['type' => 'audio']]);

        $this->assertNotEmpty($message->previewText());
    }

    public function test_preview_text_with_multiple_attachments_appends_count(): void
    {
        $message = $this->createMessage(null, [
            ['type' => 'image'],
            ['type' => 'file'],
            ['type' => 'file'],
        ]);

        $preview = $message->previewText();

        $this->assertStringContainsString('+2', $preview);
        $this->assertStringNotContainsString('+1', $preview);
    }

    public function test_preview_text_with_empty_text_and_no_attachment(): void
    {
        $message = $this->createMessage('');

        $this->assertNotEmpty($message->previewText());
    }

    public function test_attachments_return_list_as_is(): void
    {
        $message = $this->createMessage(null, [
            ['type' => 'image', 'name' => 'a.png'],
            ['type' => 'file', 'name' => 'b.pdf'],
        ]);

        $this->assertCount(2, $message->attachments());
        $this->assertSame('b.pdf', $message->attachmentAt(1)['name'] ?? null);
    }

    public function test_attachments_is_empty_when_null(): void
    {
        $message = $this->createMessage('Привет');

        $this->assertSame([], $message->attachments());
        $this->assertNull($message->attachmentAt(0));
    }

    public function test_max_chat_relation(): void
    {
        $chat = $this->makeChatWithUser(222, 111);

        $message = MaxMessage::query()->create([
            'max_chat_id' => $chat->chat_id,
            'user_id' => 111,
            'chat_id' => 222,
            'direction' => MaxMessageDirection::In,
            'sender_type' => MaxMessageSender::User,
            'text' => 'Test',
        ]);

        $this->assertInstanceOf(MaxChat::class, $message->maxChat);
        $this->assertSame($chat->chat_id, $message->maxChat->chat_id);
    }

    public function test_sender_relation_returns_author_of_incoming_message(): void
    {
        $chat = $this->makeChatWithUser(222, 111);

        $message = MaxMessage::query()->create([
            'max_chat_id' => $chat->chat_id,
            'user_id' => 111,
            'chat_id' => 222,
            'direction' => MaxMessageDirection::In,
            'sender_type' => MaxMessageSender::User,
            'text' => 'Привет',
        ]);

        $this->assertInstanceOf(MaxUser::class, $message->sender);
        $this->assertSame(111, $message->sender->user_id);
        $this->assertSame('Иван Петров', $message->senderName());
    }

    public function test_sender_name_uses_author_not_interlocutor_in_group(): void
    {
        $chat = $this->makeChat(-888, ['chat_type' => ChatType::Chat]);
        $this->makeRegistryUser(111, ['first_name' => 'Иван', 'last_name' => 'Петров']);
        $this->makeRegistryUser(222, ['first_name' => 'Мария', 'last_name' => 'Сидорова']);
        $this->linkChatUser(-888, 111);
        $this->linkChatUser(-888, 222);

        $message = MaxMessage::query()->create([
            'max_chat_id' => $chat->chat_id,
            'user_id' => 222,
            'chat_id' => -888,
            'direction' => MaxMessageDirection::In,
            'sender_type' => MaxMessageSender::User,
            'text' => 'Привет',
        ]);

        // Собеседник группы — первый не-бот (111), но подпись должна быть у автора (222).
        $this->assertSame('Мария Сидорова', $message->senderName());
    }

    public function test_sender_name_is_null_for_outgoing_message(): void
    {
        $chat = $this->makeChatWithUser(222, 111);

        $message = MaxMessage::query()->create([
            'max_chat_id' => $chat->chat_id,
            'user_id' => 111,
            'chat_id' => 222,
            'direction' => MaxMessageDirection::Out,
            'sender_type' => MaxMessageSender::Operator,
            'text' => 'Ответ',
        ]);

        $this->assertNull($message->senderName());
    }

    public function test_sender_name_falls_back_to_chat_label_without_sender(): void
    {
        $chat = $this->makeChat(-777, ['chat_type' => ChatType::Channel, 'title' => 'Новости']);

        $message = MaxMessage::query()->create([
            'max_chat_id' => $chat->chat_id,
            'user_id' => null,
            'chat_id' => -777,
            'direction' => MaxMessageDirection::In,
            'sender_type' => MaxMessageSender::User,
            'text' => 'Анонс',
        ]);

        $this->assertSame(__('filament-max-chat::chat.sender.channel'), $message->senderName());
    }

    /**
     * @param  array{type: string, path?: string, name?: string, mime?: string, size?: int}|list<array{type: string, path?: string, name?: string, mime?: string, size?: int}>|null  $attachment
     */
    private function createMessage(?string $text, ?array $attachment = null): MaxMessage
    {
        $chat = $this->makeChatWithUser(222, 111);

        return MaxMessage::query()->create([
            'max_chat_id' => $chat->chat_id,
            'user_id' => 111,
            'chat_id' => 222,
            'direction' => MaxMessageDirection::In,
            'sender_type' => MaxMessageSender::User,
            'text' => $text,
            'attachment' => $attachment,
        ]);
    }
}
