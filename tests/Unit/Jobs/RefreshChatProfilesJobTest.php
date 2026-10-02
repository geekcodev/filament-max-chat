<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Jobs;

use GeekCo\FilamentMaxChat\Jobs\RefreshChatProfilesJob;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Tests\Support\MakesChats;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Services\MaxUserProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RefreshChatProfilesJobTest extends TestCase
{
    use MakesChats;
    use RefreshDatabase;

    public function test_resolve_active_chat_returns_the_active_chat(): void
    {
        $this->createChat(userId: 111, chatId: 222);

        $job = new RefreshChatProfilesJob();
        $chat = $job->resolveActiveChat(111);

        $this->assertNotNull($chat);
        $this->assertSame(222, $chat->chat_id);
        $this->assertNotNull($chat->interlocutor());
    }

    public function test_resolve_active_chat_skips_stopped_chat(): void
    {
        $this->createChat(userId: 111, chatId: 222, status: MaxChatStatus::Stopped);

        $job = new RefreshChatProfilesJob();
        $chat = $job->resolveActiveChat(111);

        $this->assertNull($chat);
    }

    public function test_resolve_active_chat_returns_null_for_missing_user(): void
    {
        $job = new RefreshChatProfilesJob();

        $this->assertNull($job->resolveActiveChat(999));
    }

    public function test_handle_calls_profile_service_for_found_chats(): void
    {
        $this->makeRegistryUser(111, [
            'first_name' => 'Иван',
            'avatar_url' => 'https://example/avatar.jpg',
            'full_avatar_url' => 'https://example/full.jpg',
            'profile_checked_at' => now(),
        ]);

        $this->createChat(userId: 111, chatId: 222);

        $job = new RefreshChatProfilesJob([['user_id' => 111, 'chat_id' => 222]]);

        $job->handle(app(MaxUserProfileService::class));

        $this->addToAssertionCount(1);
    }

    public function test_handle_skips_entries_without_interlocutor(): void
    {
        $this->makeChat(555);

        $job = new RefreshChatProfilesJob([['user_id' => 444, 'chat_id' => 555]]);

        $job->handle(app(MaxUserProfileService::class));

        $this->addToAssertionCount(1);
    }

    public function test_handle_ignores_unknown_users(): void
    {
        $job = new RefreshChatProfilesJob([['user_id' => 999, 'chat_id' => 1]]);

        $job->handle(app(MaxUserProfileService::class));

        $this->addToAssertionCount(1);
    }

    private function createChat(
        int $userId,
        int $chatId,
        MaxChatStatus $status = MaxChatStatus::Active,
    ): MaxChat {
        $this->makeRegistryUser($userId, ['first_name' => 'Иван']);

        $chat = $this->makeChat($chatId, ['status' => $status]);

        $this->linkChatUser($chatId, $userId);

        return $chat;
    }
}
