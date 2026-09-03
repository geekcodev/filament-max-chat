<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Services;

use GeekCo\FilamentMaxChat\Jobs\RefreshChatProfilesJob;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Services\ChatProfileRefresher;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class ChatProfileRefresherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_refresh_for_chat_dispatches_job_when_avatar_empty(): void
    {
        $refresh = app(ChatProfileRefresher::class);
        $chat = $this->createChat(false);

        $refresh->refreshForChat($chat->id);

        Queue::assertPushed(RefreshChatProfilesJob::class, static function (RefreshChatProfilesJob $job): bool {
            return $job->users === [['user_id' => 111, 'chat_id' => 222]];
        });
    }

    public function test_refresh_for_chat_skips_when_avatar_is_present_and_refresh_existing_disabled(): void
    {
        $refresh = app(ChatProfileRefresher::class);
        $chat = $this->createChat(avatar: true);

        $refresh->refreshForChat($chat->id);

        Queue::assertNotPushed(RefreshChatProfilesJob::class);
    }

    public function test_refresh_for_chat_skips_within_cache_ttl(): void
    {
        Cache::put('filament-max-chat:profile_checked:111', true);

        $refresh = app(ChatProfileRefresher::class);
        $chat = $this->createChat(false);

        $refresh->refreshForChat($chat->id);

        Queue::assertNotPushed(RefreshChatProfilesJob::class);
    }

    public function test_refresh_for_chat_dispatches_when_refresh_existing_enabled(): void
    {
        config()->set('filament-max-chat.profile.refresh_existing', true);

        $refresh = app(ChatProfileRefresher::class);
        $chat = $this->createChat(avatar: true);

        $refresh->refreshForChat($chat->id);

        Queue::assertPushed(RefreshChatProfilesJob::class);
    }

    public function test_refresh_for_conversations_dispatches_only_pending_users(): void
    {
        $refresh = app(ChatProfileRefresher::class);

        $emptyAvatar = $this->createChat(avatar: false, userId: 111);
        $fullAvatar = $this->createChat(avatar: true, userId: 222);

        $refresh->refreshForConversations(collect([$emptyAvatar, $fullAvatar]));

        Queue::assertPushed(RefreshChatProfilesJob::class, static function (RefreshChatProfilesJob $job): bool {
            return $job->users === [['user_id' => 111, 'chat_id' => 222]];
        });
    }

    public function test_no_job_when_disabled(): void
    {
        config()->set('filament-max-chat.profile.enabled', false);

        $refresh = app(ChatProfileRefresher::class);
        $chat = $this->createChat(avatar: false);

        $refresh->refreshForChat($chat->id);

        Queue::assertNotPushed(RefreshChatProfilesJob::class);
    }

    public function test_no_job_when_chat_has_no_max_user(): void
    {
        $refresh = app(ChatProfileRefresher::class);

        $chat = MaxChat::query()->create([
            'user_id' => 444,
            'chat_id' => 555,
            'status' => MaxChatStatus::Active,
            'last_activity_at' => now(),
        ]);

        $refresh->refreshForChat($chat->id);

        Queue::assertNotPushed(RefreshChatProfilesJob::class);
    }

    public function test_refresh_for_conversations_skips_chat_without_max_user(): void
    {
        $refresh = app(ChatProfileRefresher::class);

        $noUserChat = MaxChat::query()->create([
            'user_id' => 444,
            'chat_id' => 555,
            'status' => MaxChatStatus::Active,
            'last_activity_at' => now(),
        ]);
        $withUserChat = $this->createChat(avatar: false, userId: 111);

        $refresh->refreshForConversations(collect([$noUserChat, $withUserChat]));

        Queue::assertPushed(RefreshChatProfilesJob::class, static function (RefreshChatProfilesJob $job): bool {
            return $job->users === [['user_id' => 111, 'chat_id' => 222]];
        });
    }

    private function createChat(bool $avatar, int $userId = 111): MaxChat
    {
        MaxUser::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'first_name' => 'Иван',
                'avatar_url' => $avatar ? 'https://example/avatar.jpg' : null,
                'full_avatar_url' => $avatar ? 'https://example/full.jpg' : null,
            ],
        );

        return MaxChat::query()->create([
            'user_id' => $userId,
            'chat_id' => $userId === 111 ? 222 : 1001,
            'status' => MaxChatStatus::Active,
            'last_activity_at' => now(),
        ]);
    }
}
