<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Jobs;

use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
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
            $chat = $this->resolveActiveChat((int) $entry['user_id']);

            $user = $chat?->maxUser;

            if ($user === null) {
                continue;
            }

            $profile->ensureAvatar($user, $chat->chat_id);
        }
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
            ->with('maxUser')
            ->where('user_id', $userId)
            ->whereNotNull('chat_id')
            ->where('status', MaxChatStatus::Active)
            ->orderByDesc('last_activity_at')
            ->first();

        return $chat;
    }
}
