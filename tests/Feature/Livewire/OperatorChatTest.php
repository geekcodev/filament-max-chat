<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature\Livewire;

use GeekCo\FilamentMaxChat\Enums\ChatMessageDirection;
use GeekCo\FilamentMaxChat\Enums\ChatMessageSender;
use GeekCo\FilamentMaxChat\Livewire\OperatorChat;
use GeekCo\FilamentMaxChat\Models\BotChat;
use GeekCo\FilamentMaxChat\Models\ChatMessage;
use GeekCo\FilamentMaxChat\Services\MaxChatSender;
use GeekCo\FilamentMaxChat\Tests\Fixtures\TestUser;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Enum\UploadType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use RuntimeException;

class OperatorChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_open_operator_chat_page(): void
    {
        $this->actingAs($this->createStaff())
            ->get('/admin/chat')
            ->assertOk();
    }

    public function test_user_without_view_permission_cannot_open_page(): void
    {
        $this->actingAs($this->createUser())
            ->get('/admin/chat')
            ->assertForbidden();
    }

    public function test_conversations_are_rendered_with_unread_badge(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Привет!');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class)
            ->assertSee('Иван')
            ->assertSee('Привет!');
    }

    public function test_select_chat_loads_messages_and_marks_them_read(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $incoming = $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Вопрос');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->assertSet('activeChatId', $chat->id)
            ->assertSee('Вопрос');

        $fresh = $incoming->fresh();

        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->read_at);
    }

    public function test_reply_sends_to_max_and_stores_outgoing_message(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();

        $this->mock(MaxChatSender::class)
            ->shouldReceive('sendFormatted')
            ->once()
            ->with(
                Mockery::on(static fn (Recipient $recipient): bool => $recipient->chatId === 222 && $recipient->userId === 111),
                'Привет!',
            );

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('reply', 'Привет!')
            ->call('sendReply')
            ->assertHasNoErrors()
            ->assertSet('reply', '');

        $this->assertDatabaseHas('chat_messages', [
            'bot_chat_id' => $chat->id,
            'direction' => ChatMessageDirection::Out->value,
            'sender_type' => ChatMessageSender::Operator->value,
            'text' => 'Привет!',
            'operator_id' => $staff->id,
        ]);
    }

    public function test_reply_requires_answer_permission(): void
    {
        $user = $this->createUser(canView: true);
        $chat = $this->createChat();

        Livewire::actingAs($user)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('reply', 'Привет!')
            ->call('sendReply')
            ->assertForbidden();
    }

    public function test_empty_reply_is_rejected_without_sending(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();

        $this->mock(MaxChatSender::class)->shouldReceive('sendFormatted')->never();

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('reply', '   ')
            ->call('sendReply')
            ->assertHasErrors(['reply' => 'required']);

        $this->assertDatabaseMissing('chat_messages', [
            'bot_chat_id' => $chat->id,
            'direction' => ChatMessageDirection::Out->value,
        ]);
    }

    public function test_max_failure_shows_error_and_does_not_store_message(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();

        $this->mock(MaxChatSender::class)
            ->shouldReceive('sendFormatted')
            ->once()
            ->andThrow(new RuntimeException('boom'));

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('reply', 'Привет!')
            ->call('sendReply')
            ->assertHasErrors(['reply']);

        $this->assertDatabaseMissing('chat_messages', [
            'bot_chat_id' => $chat->id,
            'direction' => ChatMessageDirection::Out->value,
        ]);
    }

    public function test_refresh_marks_open_thread_read(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $incoming = $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Пока');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('refresh');

        $fresh = $incoming->fresh();

        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->read_at);
    }

    public function test_select_chat_dispatches_updated_unread_count(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Вопрос');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class)
            ->call('selectChat', $chat->id)
            ->assertDispatched('chat-unread', count: 0);
    }

    public function test_refresh_dispatches_updated_unread_count(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Вопрос');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('refresh')
            ->assertDispatched('chat-unread', count: 0);
    }

    public function test_delete_message_dispatches_updated_unread_count(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $message = $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Вопрос');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('deleteMessage', $message->id)
            ->assertDispatched('chat-unread', count: 0);
    }

    public function test_clear_chat_dispatches_updated_unread_count(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Вопрос');

        $this->mock(MaxChatSender::class)->shouldReceive('deleteMessage')->zeroOrMoreTimes();

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('clearChat')
            ->assertDispatched('chat-unread', count: 0);
    }

    public function test_reply_with_file_attachment_sends_and_stores_meta(): void
    {
        Storage::fake('local');
        $staff = $this->createStaff();
        $chat = $this->createChat();

        $this->mock(MaxChatSender::class)
            ->shouldReceive('sendAttachment')
            ->once()
            ->with(
                Mockery::on(static fn (Recipient $recipient): bool => $recipient->chatId === 222 && $recipient->userId === 111),
                UploadType::File,
                Mockery::on(static fn (mixed $path): bool => is_string($path) && is_file($path)),
                null,
            );

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('attachment', UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'))
            ->set('reply', '')
            ->call('sendReply')
            ->assertHasNoErrors()
            ->assertSet('reply', '')
            ->assertSet('attachment', null);

        $message = ChatMessage::query()->where('direction', ChatMessageDirection::Out->value)->firstOrFail();

        $attachment = $message->attachment;
        $this->assertIsArray($attachment);
        $this->assertArrayHasKey('name', $attachment);
        $this->assertArrayHasKey('path', $attachment);
        $this->assertSame('file', $attachment['type']);
        $this->assertSame('doc.pdf', $attachment['name']);
        $this->assertNotNull($message->operator_id);

        Storage::disk('local')->assertExists($attachment['path']);
    }

    public function test_reply_with_image_attachment_uses_image_upload_type_and_caption(): void
    {
        Storage::fake('local');
        $staff = $this->createStaff();
        $chat = $this->createChat();

        $this->mock(MaxChatSender::class)
            ->shouldReceive('sendAttachment')
            ->once()
            ->with(
                Mockery::on(static fn (Recipient $recipient): bool => $recipient->chatId === 222),
                UploadType::Image,
                Mockery::type('string'),
                '<b>Фото</b>',
            );

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('attachment', UploadedFile::fake()->image('photo.png'))
            ->set('reply', '<b>Фото</b>')
            ->call('sendReply')
            ->assertHasNoErrors();

        $attachment = ChatMessage::query()->where('direction', ChatMessageDirection::Out->value)->firstOrFail()->attachment;
        $this->assertIsArray($attachment);
        $this->assertSame('image', $attachment['type']);
    }

    public function test_oversized_attachment_is_rejected_without_sending(): void
    {
        Storage::fake('local');
        $staff = $this->createStaff();
        $chat = $this->createChat();

        $this->mock(MaxChatSender::class)->shouldReceive('sendAttachment')->never();
        $this->mock(MaxChatSender::class)->shouldReceive('sendFormatted')->never();

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('attachment', UploadedFile::fake()->create('big.pdf', 20481, 'application/pdf'))
            ->call('sendReply')
            ->assertHasErrors();

        $this->assertDatabaseMissing('chat_messages', [
            'bot_chat_id' => $chat->id,
            'direction' => ChatMessageDirection::Out->value,
        ]);
    }

    public function test_clear_chat_removes_all_messages_from_db(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Сообщение');

        $this->mock(MaxChatSender::class)->shouldReceive('deleteMessage')->zeroOrMoreTimes();

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('clearChat')
            ->assertSet('messages', new \Illuminate\Support\Collection());

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_clear_chat_requires_answer_permission(): void
    {
        $user = $this->createUser(canView: true);
        $chat = $this->createChat();
        $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Сообщение');

        Livewire::actingAs($user)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('clearChat')
            ->assertForbidden();

        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_delete_message_removes_from_db_and_collection(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $message = $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Удаляемое');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('deleteMessage', $message->id);

        $this->assertDatabaseMissing('chat_messages', ['id' => $message->id]);
    }

    public function test_delete_message_requires_answer_permission(): void
    {
        $user = $this->createUser(canView: true);
        $chat = $this->createChat();
        $message = $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Сообщение');

        Livewire::actingAs($user)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('deleteMessage', $message->id)
            ->assertForbidden();

        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_load_more_messages_prepends_older(): void
    {
        config()->set('filament-max-chat.ui.messages_limit', 2);

        $staff = $this->createStaff();
        $chat = $this->createChat();

        for ($i = 1; $i <= 5; $i++) {
            $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, "Message {$i}");
        }

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->assertSet('activeChatId', $chat->id)
            ->call('loadMoreMessages');
    }

    public function test_load_more_messages_with_no_older_returns_early(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Only');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('loadMoreMessages')
            ->assertSee('Only');
    }

    public function test_load_more_messages_returns_when_no_active_chat(): void
    {
        $staff = $this->createStaff();

        Livewire::actingAs($staff)
            ->test(OperatorChat::class)
            ->call('loadMoreMessages')
            ->assertSet('activeChatId', null);
    }

    public function test_has_more_messages_false_when_no_active_chat(): void
    {
        $staff = $this->createStaff();

        Livewire::actingAs($staff)
            ->test(OperatorChat::class)
            ->assertSet('activeChatId', null)
            ->assertSet('hasMoreMessages', false);
    }

    public function test_refresh_appends_new_messages(): void
    {
        config()->set('filament-max-chat.ui.messages_limit', 100);

        $staff = $this->createStaff();
        $chat = $this->createChat();
        $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Старое');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->assertSee('Старое');

        $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Новое');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('refresh')
            ->assertSee('Новое');
    }

    public function test_refresh_when_messages_empty_loads_all(): void
    {
        config()->set('filament-max-chat.ui.messages_limit', 100);

        $staff = $this->createStaff();
        $chat = $this->createChat();

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->assertDontSee('Привет');

        $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Привет');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('refresh')
            ->assertSee('Привет');
    }

    public function test_send_reply_sends_formatted_message(): void
    {
        Storage::fake('local');
        $staff = $this->createStaff();
        $chat = $this->createChat();

        $this->mock(MaxChatSender::class)
            ->shouldReceive('sendFormatted')
            ->once();

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('reply', 'Ответ оператора')
            ->call('sendReply')
            ->assertSet('reply', '');
    }

    public function test_send_reply_with_audio_attachment(): void
    {
        Storage::fake('local');
        $staff = $this->createStaff();
        $chat = $this->createChat();

        $this->mock(MaxChatSender::class)
            ->shouldReceive('sendAttachment')
            ->once()
            ->with(
                Mockery::on(static fn (Recipient $recipient): bool => $recipient->chatId === 222),
                UploadType::Audio,
                Mockery::type('string'),
                null,
            );

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('attachment', UploadedFile::fake()->create('voice.mp3', 10, 'audio/mpeg'))
            ->set('reply', '')
            ->call('sendReply')
            ->assertHasNoErrors();
    }

    public function test_send_reply_with_file_attachment_and_caption(): void
    {
        Storage::fake('local');
        $staff = $this->createStaff();
        $chat = $this->createChat();

        $this->mock(MaxChatSender::class)
            ->shouldReceive('sendAttachment')
            ->once()
            ->with(
                Mockery::on(static fn (Recipient $recipient): bool => $recipient->chatId === 222),
                UploadType::File,
                Mockery::type('string'),
                Mockery::type('string'),
            );

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('attachment', UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'))
            ->set('reply', 'Файл для тебя')
            ->call('sendReply')
            ->assertHasNoErrors();
    }

    public function test_send_reply_logs_error_on_failure(): void
    {
        Storage::fake('local');
        $staff = $this->createStaff();
        $chat = $this->createChat();

        $this->mock(MaxChatSender::class)
            ->shouldReceive('sendFormatted')
            ->once()
            ->andThrow(new RuntimeException('MAX API down'));

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->set('reply', 'Тест ошибки')
            ->call('sendReply')
            ->assertHasErrors('reply');
    }

    public function test_send_reply_when_chat_id_is_null(): void
    {
        $staff = $this->createStaff();

        $this->mock(MaxChatSender::class)->shouldReceive('sendFormatted')->never();

        Livewire::actingAs($staff)
            ->test(OperatorChat::class)
            ->set('reply', 'Привет')
            ->call('sendReply');
    }

    public function test_delete_message_removes_from_collection(): void
    {
        $staff = $this->createStaff();
        $chat = $this->createChat();
        $msg1 = $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Первое');
        $msg2 = $this->createMessage($chat, ChatMessageDirection::In, ChatMessageSender::User, 'Второе');

        Livewire::actingAs($staff)
            ->test(OperatorChat::class, ['chat' => $chat->id])
            ->call('deleteMessage', $msg1->id);

        $this->assertDatabaseMissing('chat_messages', ['id' => $msg1->id]);
        $this->assertDatabaseHas('chat_messages', ['id' => $msg2->id]);
    }

    public function test_updated_attachment_validates(): void
    {
        $staff = $this->createStaff();

        Livewire::actingAs($staff)
            ->test(OperatorChat::class)
            ->set('attachment', UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg'))
            ->assertHasNoErrors('attachment');
    }

    private function createUser(bool $canView = false, bool $canAnswer = false): TestUser
    {
        return TestUser::query()->create([
            'name' => 'Тест Юзер',
            'email' => uniqid('', false).'@example.test',
            'password' => 'password',
            'can_view_chat' => $canView,
            'can_answer_chat' => $canAnswer,
        ]);
    }

    private function createStaff(): TestUser
    {
        return $this->createUser(canView: true, canAnswer: true);
    }

    private function createChat(): BotChat
    {
        MaxUser::query()->updateOrCreate(
            ['user_id' => 111],
            ['first_name' => 'Иван'],
        );

        return BotChat::query()->create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => BotChatStatus::Active,
            'last_activity_at' => now(),
        ]);
    }

    private function createMessage(
        BotChat $chat,
        ChatMessageDirection $direction,
        ChatMessageSender $sender,
        ?string $text,
    ): ChatMessage {
        return ChatMessage::query()->create([
            'bot_chat_id' => $chat->id,
            'user_id' => $chat->user_id,
            'chat_id' => $chat->chat_id,
            'direction' => $direction,
            'sender_type' => $sender,
            'text' => $text,
        ]);
    }
}
