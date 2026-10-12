<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\FilamentMaxChat\Jobs\RefreshChatProfilesJob;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxChatUser;
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

        $chat = $this->chatModel()::query()
            ->with('chatUsers.maxUser')
            ->find($maxChatId);

        if ($chat === null) {
            return;
        }

        $pending = $this->pendingForChat($chat);

        if ($pending !== []) {
            $this->dispatch($pending);
        }
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
            $pending = [...$pending, ...$this->pendingForChat($chat)];
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

    /**
     * Все собеседники чата, которым нужно обновить профиль. Раньше проверялся
     * только interlocutor() (первый не-бот), поэтому в группе аватары остальных
     * участников не подгружались, и у каждого входящего сообщения показывался
     * аватар одного и того же собеседника.
     *
     * @return list<array{user_id: int, chat_id: int|null}>
     */
    private function pendingForChat(MaxChat $chat): array
    {
        $pending = [];

        foreach ($this->participants($chat) as $user) {
            if (! $this->isDue($user)) {
                continue;
            }

            $pending[] = ['user_id' => (int) $user->user_id, 'chat_id' => $chat->chat_id];
        }

        return $pending;
    }

    /**
     * Не-боты из состава чата: у диалога один, у группы и канала — все
     * участники, заведённые в реестре max_chat_users.
     *
     * @return Collection<int, MaxUser>
     */
    private function participants(MaxChat $chat): Collection
    {
        if ($chat->relationLoaded('chatUsers')) {
            /** @var Collection<int, MaxChatUser> $links */
            $links = $chat->getRelation('chatUsers');
        } else {
            /** @var Collection<int, MaxChatUser> $links */
            $links = $chat->chatUsers()->with('maxUser')->get();
        }

        $users = [];

        foreach ($links as $link) {
            /** @var MaxUser|null $user */
            $user = $link->maxUser;

            if ($user !== null && ! $user->is_bot) {
                $users[] = $user;
            }
        }

        return new Collection($users);
    }

    private function isDue(MaxUser $user): bool
    {
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
