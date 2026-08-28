<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use GeekCo\FilamentMaxChat\Tests\Fixtures\TestUser;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
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

        $this->createMessage($chatA, MaxMessageDirection::In, 'Привет!');
        $this->createMessage($chatA, MaxMessageDirection::Out, 'Здравствуйте!');
        $this->createMessage($chatB, MaxMessageDirection::In, 'Ещё вопрос');

        $response = $this->actingAs($this->createUser(canView: true))
            ->get('/admin/chat/unread-count');

        $response->assertOk();
        $response->assertJsonPath('unread_count', 2);
        $response->assertJsonPath('latest_max_chat_id', $chatB->id);
    }

    public function test_staff_gets_zero_when_no_unread(): void
    {
        $chat = $this->createChat(111, 222);
        $this->createMessage($chat, MaxMessageDirection::Out, 'Исходящее');

        $response = $this->actingAs($this->createUser(canView: true))
            ->get('/admin/chat/unread-count');

        $response->assertOk();
        $response->assertJsonPath('unread_count', 0);
        $response->assertJsonPath('latest_max_chat_id', null);
    }

    public function test_staff_gets_zero_after_marking_read(): void
    {
        $chat = $this->createChat(111, 222);
        $this->createMessage($chat, MaxMessageDirection::In, 'Привет!');

        $staff = $this->createUser(canView: true);
        $this->actingAs($staff)->get('/admin/chat/unread-count');

        app(MaxMessageService::class)->markRead($chat->id);

        $this->actingAs($staff)
            ->get('/admin/chat/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('latest_max_chat_id', null);
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

    private function createChat(int $userId, int $chatId): MaxChat
    {
        MaxUser::query()->updateOrCreate(['user_id' => $userId], ['first_name' => 'Пользователь']);

        return MaxChat::query()->create([
            'user_id' => $userId,
            'chat_id' => $chatId,
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
            'sender_type' => $direction === MaxMessageDirection::In ? MaxMessageSender::User : MaxMessageSender::Operator,
            'text' => $text,
        ]);
    }
}
