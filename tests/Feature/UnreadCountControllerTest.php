<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature;

use GeekCo\FilamentMaxChat\Enums\ChatMessageDirection;
use GeekCo\FilamentMaxChat\Enums\ChatMessageSender;
use GeekCo\FilamentMaxChat\Models\BotChat;
use GeekCo\FilamentMaxChat\Models\ChatMessage;
use GeekCo\FilamentMaxChat\Services\ChatMessageService;
use GeekCo\FilamentMaxChat\Tests\Fixtures\TestUser;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UnreadCountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_unauthorized_json(): void
    {
        $this->get('/admin/chat/unread-count')
            ->assertUnauthorized()
            ->assertJson(['error' => 'Unauthenticated.']);
    }

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $this->actingAs($this->createUser())
            ->get('/admin/chat/unread-count')
            ->assertForbidden()
            ->assertJson(['error' => 'Forbidden.']);
    }

    public function test_staff_with_view_permission_gets_unread_counts(): void
    {
        $chatA = $this->createChat(111, 222);
        $chatB = $this->createChat(333, 444);

        $this->createMessage($chatA, ChatMessageDirection::In, 'Привет!');
        $this->createMessage($chatA, ChatMessageDirection::Out, 'Здравствуйте!');
        $this->createMessage($chatB, ChatMessageDirection::In, 'Ещё вопрос');

        $response = $this->actingAs($this->createUser(canView: true))
            ->get('/admin/chat/unread-count');

        $response->assertOk();
        $response->assertJsonPath('unread_count', 2);
        $response->assertJsonPath('latest_bot_chat_id', $chatB->id);
    }

    public function test_staff_gets_zero_when_no_unread(): void
    {
        $chat = $this->createChat(111, 222);
        $this->createMessage($chat, ChatMessageDirection::Out, 'Исходящее');

        $response = $this->actingAs($this->createUser(canView: true))
            ->get('/admin/chat/unread-count');

        $response->assertOk();
        $response->assertJsonPath('unread_count', 0);
        $response->assertJsonPath('latest_bot_chat_id', null);
    }

    public function test_staff_gets_zero_after_marking_read(): void
    {
        $chat = $this->createChat(111, 222);
        $this->createMessage($chat, ChatMessageDirection::In, 'Привет!');

        $staff = $this->createUser(canView: true);
        $this->actingAs($staff)->get('/admin/chat/unread-count');

        app(ChatMessageService::class)->markRead($chat->id);

        $this->actingAs($staff)
            ->get('/admin/chat/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('latest_bot_chat_id', null);
    }

    private function createUser(bool $canView = false): TestUser
    {
        return TestUser::query()->create([
            'name' => 'Тест Юзер',
            'email' => uniqid('', false).'@example.test',
            'password' => 'password',
            'can_view_chat' => $canView,
            'can_answer_chat' => false,
        ]);
    }

    private function createChat(int $userId, int $chatId): BotChat
    {
        MaxUser::query()->updateOrCreate(['user_id' => $userId], ['first_name' => 'Пользователь']);

        return BotChat::query()->create([
            'user_id' => $userId,
            'chat_id' => $chatId,
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
            'sender_type' => $direction === ChatMessageDirection::In ? ChatMessageSender::User : ChatMessageSender::Operator,
            'text' => $text,
        ]);
    }
}
