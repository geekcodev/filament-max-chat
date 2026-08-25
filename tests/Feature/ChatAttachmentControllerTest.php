<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature;

use GeekCo\FilamentMaxChat\Enums\ChatMessageDirection;
use GeekCo\FilamentMaxChat\Enums\ChatMessageSender;
use GeekCo\FilamentMaxChat\Models\BotChat;
use GeekCo\FilamentMaxChat\Models\ChatMessage;
use GeekCo\FilamentMaxChat\Tests\Fixtures\TestUser;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class ChatAttachmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_with_view_permission_can_download_attachment(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('chat-attachments/test.png', 'png-content');

        $message = $this->createMessage(attachment: [
            'type' => 'image',
            'path' => 'chat-attachments/test.png',
            'name' => 'photo.png',
            'mime' => 'image/png',
            'size' => 11,
        ]);

        $response = $this->actingAs($this->createUser(canView: true))
            ->get("/admin/chat/messages/{$message->id}/attachment");

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('png-content', $response->streamedContent());
    }

    public function test_guest_gets_redirected_to_login(): void
    {
        $message = $this->createMessage();

        $this->get("/admin/chat/messages/{$message->id}/attachment")
            ->assertRedirect('/admin/login');
    }

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $message = $this->createMessage();

        $this->actingAs($this->createUser())
            ->get("/admin/chat/messages/{$message->id}/attachment")
            ->assertForbidden();
    }

    public function test_message_without_attachment_file_is_not_found(): void
    {
        $message = $this->createMessage(attachment: ['type' => 'image']);

        $this->actingAs($this->createUser(canView: true))
            ->get("/admin/chat/messages/{$message->id}/attachment")
            ->assertNotFound();
    }

    public function test_message_with_valid_meta_but_missing_file_is_not_found(): void
    {
        $message = $this->createMessage(attachment: [
            'type' => 'image',
            'path' => 'chat-attachments/deleted.png',
            'name' => 'photo.png',
            'mime' => 'image/png',
            'size' => 11,
        ]);

        $this->actingAs($this->createUser(canView: true))
            ->get("/admin/chat/messages/{$message->id}/attachment")
            ->assertNotFound();
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

    /**
     * @param  array<string, mixed>|null  $attachment
     */
    private function createMessage(?array $attachment = null): ChatMessage
    {
        MaxUser::query()->updateOrCreate(['user_id' => 111], ['first_name' => 'Иван']);

        $chat = BotChat::query()->create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => BotChatStatus::Active,
            'last_activity_at' => now(),
        ]);

        return ChatMessage::query()->create([
            'bot_chat_id' => $chat->id,
            'user_id' => $chat->user_id,
            'chat_id' => $chat->chat_id,
            'direction' => ChatMessageDirection::In,
            'sender_type' => ChatMessageSender::User,
            'text' => null,
            'attachment' => $attachment,
        ]);
    }
}
