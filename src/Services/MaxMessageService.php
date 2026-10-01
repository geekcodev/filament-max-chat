<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Events\MaxMessageCreated;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxChatUser;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MaxMessageService
{
    public function __construct(
        private readonly MaxAttachmentStore $attachments,
        private readonly Logger $logger,
    ) {
    }

    public function storeIncoming(Update $update): ?MaxMessage
    {
        $user = $update->user ?? $update->message?->sender;
        $chatId = $update->chatId ?? $update->message?->recipient->chatId;

        if ($user === null || $chatId === null) {
            $this->logger->log('warning', 'Incoming MAX message without user or chat skipped.', [
                'update_type' => $update->updateType->value,
            ]);

            return null;
        }

        $maxChat = $this->upsertChat($user->userId, $chatId, $user);

        $text = $update->message?->body?->text;
        $text ??= $update->message?->body?->caption;

        return $this->createMessage(
            maxChat: $maxChat,
            userId: $user->userId,
            direction: MaxMessageDirection::In,
            senderType: MaxMessageSender::User,
            text: $text,
            messageId: $update->messageId ?? $update->message?->body?->mid,
            attachments: $this->attachments->storeFromIncoming($update->message?->body?->attachments),
        );
    }

    /**
     * Сохранить входящее пользовательское сообщение с произвольным текстом
     * (не из апдейта): заявки/leads, «Позвать оператора» и пр.
     * Обновляет профиль max_users (имя чата) из переданного User либо из реестра,
     * сохраняет сообщение как непрочитанное входящее (direction=In, sender=User).
     * В MAX ничего не отправляется — только история + broadcast.
     *
     * @param string|null $messageId внешний message_id (уникальность/источник)
     */
    public function storeIncomingForUser(
        int $userId,
        int $chatId,
        ?User $user,
        ?string $text,
        ?string $messageId = null,
    ): ?MaxMessage {
        $user ??= $this->userFromRegistry($userId);

        if ($user === null) {
            $this->logger->log('warning', 'Incoming MAX message for user skipped: profile unavailable.', [
                'user_id' => $userId,
                'chat_id' => $chatId,
            ]);

            return null;
        }

        $maxChat = $this->upsertChat($userId, $chatId, $user);

        return $this->createMessage(
            maxChat: $maxChat,
            userId: $userId,
            direction: MaxMessageDirection::In,
            senderType: MaxMessageSender::User,
            text: $text,
            messageId: $messageId,
        );
    }

    /**
     * @param list<array{type: string, path?: string, name?: string, mime?: string, size?: int}> $attachments
     */
    public function storeOutgoing(
        int $userId,
        int $chatId,
        ?string $text,
        MaxMessageSender $sender,
        ?int $operatorId = null,
        ?string $messageId = null,
        array $attachments = [],
    ): ?MaxMessage {
        $maxChat = $this->upsertChat($userId, $chatId);

        return $this->createMessage(
            maxChat: $maxChat,
            userId: $userId,
            direction: MaxMessageDirection::Out,
            senderType: $sender,
            text: $text,
            operatorId: $operatorId,
            messageId: $messageId,
            attachments: $attachments,
        );
    }

    /**
     * @return Collection<int, MaxChat>
     */
    public function conversations(): Collection
    {
        return $this->chatModel()::query()
            ->where('status', MaxChatStatus::Active)
            ->withCount([
                'messages as unread_count' => static function (Builder $query): void {
                    $query->where('direction', MaxMessageDirection::In)
                        ->whereNull('read_at');
                },
            ])
            ->with(['lastMessage', 'chatUsers.maxUser'])
            ->orderByDesc('last_activity_at')
            ->get();
    }

    /**
     * @return Collection<int, MaxChat>
     */
    public function searchConversations(string $term): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return new Collection();
        }

        $escaped = str_replace(
            ['\\', '!', '%', '_'],
            ['\\\\', '!!', '!%', '!_'],
            $term,
        );

        $like = '%' . $escaped . '%';

        $chatIds = DB::table('max_chats')
            ->join('max_chat_users', 'max_chat_users.chat_id', '=', 'max_chats.chat_id')
            ->join('max_users', 'max_users.user_id', '=', 'max_chat_users.user_id')
            ->leftJoin('max_chat_messages', 'max_chat_messages.max_chat_id', '=', 'max_chats.chat_id')
            ->where('max_chats.status', MaxChatStatus::Active)
            ->where(function (\Illuminate\Database\Query\Builder $query) use ($like): void {
                $query->whereRaw('max_users.first_name LIKE ? ESCAPE \'!\'', [$like])
                    ->orWhereRaw('max_users.last_name LIKE ? ESCAPE \'!\'', [$like])
                    ->orWhereRaw('max_chat_messages.text LIKE ? ESCAPE \'!\'', [$like]);
            })
            ->orderByDesc('max_chats.last_activity_at')
            ->distinct()
            ->pluck('max_chats.chat_id');

        if ($chatIds->isEmpty()) {
            return new Collection();
        }

        return $this->chatModel()::query()
            ->whereIn('chat_id', $chatIds)
            ->where('status', MaxChatStatus::Active)
            ->withCount([
                'messages as unread_count' => static function (Builder $query): void {
                    $query->where('direction', MaxMessageDirection::In)
                        ->whereNull('read_at');
                },
            ])
            ->with(['lastMessage', 'chatUsers.maxUser'])
            ->orderByDesc('last_activity_at')
            ->get();
    }

    /**
     * Есть ли строка реестра max_chats для указанного идентификатора чата в MAX.
     *
     * С v1.2.0 адаптера первичный ключ max_chats — сам chat_id, поэтому проверка
     * сводится к поиску по ключу. Нужна, чтобы открывать диалог по ссылке вида
     * /chat?chat_id=<id в MAX> и не падать 404 на чужом или удалённом чате.
     */
    public function chatExists(int $chatId): bool
    {
        $model = $this->chatModel();

        return $model::query()->whereKey($chatId)->exists();
    }

    /**
     * @deprecated с v1.1.0: идентификатор записи реестра совпадает с chat_id,
     *             используйте {@see self::chatExists()}
     */
    public function resolveInternalIdFromMaxChatId(int $maxChatId): ?int
    {
        return $this->chatExists($maxChatId) ? $maxChatId : null;
    }

    /**
     * @return Collection<int, MaxMessage>
     */
    public function messagesFor(int $maxChatId, ?int $limit = null): Collection
    {
        $limit ??= config()->integer('filament-max-chat.ui.messages_limit', 100);

        return MaxMessage::query()
            ->where('max_chat_id', $maxChatId)
            ->with('maxChat.chatUsers.maxUser')
            ->latest()
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * @return Collection<int, MaxMessage>
     */
    public function messagesBefore(int $maxChatId, int $beforeMessageId, ?int $limit = null): Collection
    {
        $limit ??= config()->integer('filament-max-chat.ui.messages_limit', 100);

        return MaxMessage::query()
            ->where('max_chat_id', $maxChatId)
            ->where('id', '<', $beforeMessageId)
            ->with('maxChat.chatUsers.maxUser')
            ->latest()
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    public function markRead(int $maxChatId): void
    {
        MaxMessage::query()
            ->where('max_chat_id', $maxChatId)
            ->where('direction', MaxMessageDirection::In)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function totalUnreadCount(): int
    {
        return (int) MaxMessage::query()
            ->where('direction', MaxMessageDirection::In)
            ->whereNull('read_at')
            ->count();
    }

    public function clearHistory(int $maxChatId): int
    {
        $sender = app(MaxChatSender::class);

        $messages = MaxMessage::query()
            ->where('max_chat_id', $maxChatId)
            ->whereNotNull('message_id')
            ->get();

        foreach ($messages as $message) {
            try {
                $sender->deleteMessage((string) $message->message_id);
            } catch (\Throwable $e) {
                $this->logger->log('warning', 'Failed to delete message from MAX.', [
                    'message_id' => $message->message_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return DB::table('max_chat_messages')
            ->where('max_chat_id', $maxChatId)
            ->delete();
    }

    /**
     * Убрать диалог из списка оператора: пометить запись реестра max_chats
     * статусом Removed. История сообщений сохраняется; список чатов фильтрует
     * только Active, поэтому диалог исчезает из листа.
     */
    public function removeChat(int $maxChatId): bool
    {
        $model = $this->chatModel();

        /** @var MaxChat|null $chat */
        $chat = $model::query()->find($maxChatId);

        if ($chat === null) {
            return false;
        }

        $chat->status = MaxChatStatus::Removed;
        $chat->save();

        return true;
    }

    public function deleteMessage(int $chatMessageId): bool
    {
        /** @var MaxMessage|null $message */
        $message = MaxMessage::query()->find($chatMessageId);

        if ($message === null) {
            return false;
        }

        if ($message->message_id !== null) {
            try {
                app(MaxChatSender::class)->deleteMessage($message->message_id);
            } catch (\Throwable $e) {
                $this->logger->log('warning', 'Failed to delete message from MAX.', [
                    'message_id' => $message->message_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return (bool) $message->delete();
    }

    /** @return class-string<MaxChat> */
    private function chatModel(): string
    {
        /** @var class-string<MaxChat> */
        return config()->string('filament-max-chat.chat_model', MaxChat::class);
    }

    /** @return class-string<MaxChatUser> */
    private function chatUserModel(): string
    {
        /** @var class-string<MaxChatUser> */
        return config()->string('laravel-max-client.chats.chat_users_model', MaxChatUser::class);
    }

    private function userFromRegistry(int $userId): ?User
    {
        $record = MaxUser::query()->find($userId);

        if ($record === null) {
            return null;
        }

        return new User(
            userId: (int) $record->user_id,
            firstName: (string) $record->first_name,
            lastName: $record->last_name,
            username: $record->username,
            isBot: (bool) $record->is_bot,
            lastActivityTime: $record->last_activity_time !== null ? (int) $record->last_activity_time : null,
            name: $record->name,
        );
    }

    private function upsertChat(int $userId, int $chatId, ?User $user = null): MaxChat
    {
        if ($user !== null) {
            MaxUser::query()->updateOrCreate(
                ['user_id' => $userId],
                array_filter([
                    'first_name' => $user->firstName,
                    'last_name' => $user->lastName,
                    'username' => $user->username,
                ]),
            );
        }

        $model = $this->chatModel();

        /** @var MaxChat */
        $chat = $model::query()->updateOrCreate(
            ['chat_id' => $chatId],
            [
                'status' => MaxChatStatus::Active,
                'last_activity_at' => now(),
            ],
        );

        $this->upsertChatUser($chatId, $userId);

        return $chat->refresh();
    }

    /**
     * Завести связь «чат — пользователь» в реестре max_chat_users. Слушатель
     * адаптера делает это же по апдейтам MAX, но сообщения оператора и
     * storeIncomingForUser() приходят без апдейта, поэтому связь заводит и плагин.
     */
    private function upsertChatUser(int $chatId, int $userId): void
    {
        $model = $this->chatUserModel();

        $model::query()->updateOrCreate(
            ['chat_id' => $chatId, 'user_id' => $userId],
            ['last_activity_at' => now()],
        );
    }

    /**
     * @param list<array{type: string, path?: string, name?: string, mime?: string, size?: int}> $attachments
     */
    private function createMessage(
        MaxChat $maxChat,
        int $userId,
        MaxMessageDirection $direction,
        MaxMessageSender $senderType,
        ?string $text,
        ?int $operatorId = null,
        ?string $messageId = null,
        array $attachments = [],
    ): MaxMessage {
        $maxChat->forceFill(['last_activity_at' => now()])->save();

        $message = $maxChat->messages()->create([
            'user_id' => $userId,
            'chat_id' => $maxChat->chat_id,
            'message_id' => $messageId,
            'direction' => $direction,
            'sender_type' => $senderType,
            'text' => $text,
            'attachment' => $attachments,
            'operator_id' => $operatorId,
        ]);

        MaxMessageCreated::dispatch($message);

        return $message;
    }
}
