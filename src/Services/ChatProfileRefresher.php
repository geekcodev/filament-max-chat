<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\FilamentMaxChat\Jobs\RefreshChatProfilesJob;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Решает, когда подгружать профиль/аватар пользователей MAX из API, и ставит
 * фоновую задачу (RefreshChatProfilesJob). Сам API не дёргает — только троттлит
 * по TTL и диспатчит. Аватар отображается из max_users уже после подгрузки.
 */
class ChatProfileRefresher
{
    public function refreshForChat(int $maxChatId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $chat = $this->chatModel()::query()->with('maxUser')->find($maxChatId);

        if ($chat === null || ! $this->isDue($chat)) {
            return;
        }

        $this->dispatch([
            ['user_id' => (int) $chat->user_id, 'chat_id' => $chat->chat_id],
        ]);
    }

    /**
     * @param Collection<int, MaxChat> $conversations
     */
    public function refreshForConversations(Collection $conversations): void
    {
        if (! $this->enabled()) {
            return;
        }

        $pending = [];

        foreach ($conversations as $chat) {
            if ($this->isDue($chat)) {
                $pending[] = ['user_id' => (int) $chat->user_id, 'chat_id' => $chat->chat_id];
            }
        }

        if ($pending !== []) {
            $this->dispatch($pending);
        }
    }

    /**
     * @param list<array{user_id: int, chat_id: int|null}> $users
     */
    private function dispatch(array $users): void
    {
        RefreshChatProfilesJob::dispatch($users);
    }

    private function isDue(MaxChat $chat): bool
    {
        $user = $chat->maxUser;

        if ($user === null) {
            return false;
        }

        $userId = (int) $user->user_id;

        if (Cache::has($this->cacheKey($userId))) {
            return false;
        }

        if ($this->hasFullAvatar($user) && ! $this->refreshExisting()) {
            return false;
        }

        Cache::put($this->cacheKey($userId), true, $this->ttl());

        return true;
    }

    private function hasFullAvatar(MaxUser $user): bool
    {
        return $user->avatar_url !== null
            && $user->avatar_url !== ''
            && $user->full_avatar_url !== null
            && $user->full_avatar_url !== '';
    }

    private function cacheKey(int $userId): string
    {
        return "filament-max-chat:profile_checked:{$userId}";
    }

    private function enabled(): bool
    {
        return config()->boolean('filament-max-chat.profile.enabled', true);
    }

    private function refreshExisting(): bool
    {
        return config()->boolean('filament-max-chat.profile.refresh_existing', false);
    }

    private function ttl(): int
    {
        return config()->integer('filament-max-chat.profile.cache_ttl', 86400);
    }

    /**
     * @return class-string<MaxChat>
     */
    private function chatModel(): string
    {
        /** @var class-string<MaxChat> */
        return config()->string('filament-max-chat.chat_model', MaxChat::class);
    }
}
