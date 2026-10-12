<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Jobs;

use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Services\MaxUserProfileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Фоновая подгрузка профилей/аватаров пользователей MAX (getChatMembers).
 * Диспатчится из ChatProfileRefresher; не блокирует отклик Livewire.
 */
class RefreshChatProfilesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param list<array{user_id: int, chat_id: int|null}> $users
     */
    public function __construct(public array $users = [])
    {
    }

    public function handle(MaxUserProfileService $profile): void
    {
        foreach ($this->users as $entry) {
            $userId = (int) $entry['user_id'];
            $user = $this->resolveUser($userId);

            if ($user === null || $user->is_bot) {
                continue;
            }

            $chatId = $entry['chat_id'] !== null
                ? (int) $entry['chat_id']
                : $this->resolveActiveChat($userId)?->chat_id;

            $profile->ensureAvatar($user, $chatId);
        }
    }

    /**
     * Отправитель из реестра max_users. Раньше задача искала чат по user_id и
     * обновляла его собеседника (interlocutor), игнорируя переданный user_id,
     * поэтому в группе профили остальных участников не подгружались.
     */
    public function resolveUser(int $userId): ?MaxUser
    {
        /** @var class-string<MaxUser> $model */
        $model = config()->string('laravel-max-client.users.model', MaxUser::class);

        /** @var MaxUser|null $user */
        $user = $model::query()->find($userId);

        return $user;
    }

    /**
     * @return class-string<MaxChat>
     */
    private function chatModel(): string
    {
        /** @var class-string<MaxChat> */
        return config()->string('filament-max-chat.chat_model', MaxChat::class);
    }

    public function resolveActiveChat(int $userId): ?MaxChat
    {
        $model = $this->chatModel();

        /** @var MaxChat|null $chat */
        $chat = $model::query()
            ->with('chatUsers.maxUser')
            ->whereHas('chatUsers', static function ($query) use ($userId): void {
                $query->where('user_id', $userId);
            })
            ->where('status', MaxChatStatus::Active)
            ->orderByDesc('last_activity_at')
            ->first();

        return $chat;
    }
}
