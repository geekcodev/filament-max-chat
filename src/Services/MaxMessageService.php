<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Events\MaxChatRemoved;
use GeekCo\FilamentMaxChat\Events\MaxMessageCreated;
use GeekCo\FilamentMaxChat\Events\MaxMessageDeleted;
use GeekCo\FilamentMaxChat\Events\MaxMessageUpdated;
use GeekCo\FilamentMaxChat\Events\MaxMessagesCleared;
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

/**
 * @phpstan-import-type StoredAttachment from \GeekCo\FilamentMaxChat\Services\MaxAttachmentStore
 */
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

        if ($chatId === null) {
            $this->logger->log('warning', 'Incoming MAX message without chat skipped.', [
                'update_type' => $update->updateType->value,
            ]);

            return null;
        }

        if ($user === null) {
            $this->logger->log('info', 'Incoming MAX message without sender stored without user.', [
                'chat_id' => $chatId,
                'update_type' => $update->updateType->value,
            ]);
        }

        $messageId = $update->messageId ?? $update->message?->body?->mid;

        // Ранний выход до скачивания медиа: повторная доставка апдейта не должна
        // заново сохранять вложения во приватный диск (иначе файлы-сироты).
        $existing = $this->findStoredMessage($chatId, $messageId);

        if ($existing !== null) {
            return $existing;
        }

        $maxChat = $this->upsertChat($user?->userId, $chatId, $user);

        $text = $update->message?->body?->text;
        $text ??= $update->message?->body?->caption;

        return $this->createMessage(
            maxChat: $maxChat,
            userId: $user?->userId,
            direction: MaxMessageDirection::In,
            senderType: MaxMessageSender::User,
            text: $text,
            messageId: $messageId,
            attachments: $this->attachments->storeFromIncoming($update->message?->body?->attachments),
        );
    }

    /**
     * Правка сообщения в MAX (`message_edited`): переписывает текст уже
     * сохранённого сообщения по `message_id`. Неизвестное сообщение не создаётся
     * — апдейт без тела (нет нового текста) тоже ничего не трогает, чтобы пустой
     * `message_edited` не вытер переписку. Идентификатор берётся из
     * `message.body.mid`: в `message_edited` верхнего `message_id` нет. После
     * сохранения летит broadcast-событие `chat-message.updated`, чтобы открытая
     * лента поправила строку не дожидаясь `wire:poll`.
     */
    public function applyIncomingEdit(Update $update): ?MaxMessage
    {
        $message = $this->findByMaxMessageId($update);

        if ($message === null) {
            return null;
        }

        $text = $update->message?->body?->text;
        $text ??= $update->message?->body?->caption;

        if ($text === null) {
            return null;
        }

        $message->forceFill(['text' => $text])->save();

        $message = $message->refresh();

        MaxMessageUpdated::dispatch($message);

        return $message;
    }

    /**
     * Удаление сообщения в MAX (`message_removed`): строка истории удаляется
     * вместе с файлом вложения на приватном диске — иначе файл остался бы
     * сиротой, а удалить его уже никто не сможет. Отправку в MAX не трогаем:
     * сообщение удалено там, локальная история просто приводится в соответствие.
     * После удаления летит broadcast-событие `chat-message.deleted` — модель к
     * этому моменту уже удалена, поэтому событие несёт её идентификаторы.
     */
    public function applyIncomingRemoval(Update $update): bool
    {
        $message = $this->findByMaxMessageId($update);

        if ($message === null) {
            return false;
        }

        $this->attachments->deleteStored($message->attachment);

        $chatId = $message->max_chat_id;
        $messageId = $message->message_id;
        $rowId = $message->id;

        $deleted = (bool) $message->delete();

        if ($deleted) {
            MaxMessageDeleted::dispatch($chatId, $messageId, $rowId);
        }

        return $deleted;
    }

    /**
     * Очистка истории диалога по апдейту MAX (`dialog_cleared`). Переписка уже
     * очищена на стороне MAX, поэтому локальные строки и файлы вложений удаляются
     * без вызова API. Через {@see self::clearHistory()} делать нельзя: тот метод
     * перед удалением просит MAX удалить каждое сообщение — здесь это была бы
     * повторная рассылка удалений по уже очищенному чату.
     *
     * После очистки уходит broadcast-событие `chat-message.cleared`, чтобы открытая
     * лента перечиталась целиком и глобальный счётчик непрочитанного обновился.
     */
    public function applyIncomingClear(Update $update): int
    {
        $chatId = $update->chatId;

        if ($chatId === null) {
            $this->logger->log('warning', 'Incoming MAX clear without chat skipped.', [
                'update_type' => $update->updateType->value,
            ]);

            return 0;
        }

        $query = MaxMessage::query()->where('max_chat_id', $chatId);

        $deleted = (clone $query)->count();

        if ($deleted > 0) {
            // Чанками, а не `->get()`: у долгой переписки строки могут не поместиться
            // в память, а читать нужно только колонку вложения.
            $query->select('id', 'attachment')->chunkById(200, function (Collection $messages): void {
                foreach ($messages as $message) {
                    $this->attachments->deleteStored($message->attachment);
                }
            });
        }

        MaxMessage::query()
            ->where('max_chat_id', $chatId)
            ->delete();

        if ($deleted > 0) {
            MaxMessagesCleared::dispatch($chatId);
        }

        return $deleted;
    }

    /**
     * Диалог удалён в MAX (`dialog_removed`): чат помечается статусом Removed и
     * исчезает из списка оператора. История сообщений и файлы вложений
     * сохраняются — то же поведение, что у кнопки удаления чата в интерфейсе.
     * После пометки уходит broadcast-событие `chat-removed`.
     *
     * `dialog_removed` приходит и при остановке бота (`bot_stopped`, см. спеку MAX),
     * а статус в этом случае принадлежит слушателю laravel-max-client (Stopped).
     * Поэтому неактивный чат не понижается до Removed: иначе итоговый статус
     * зависел бы от порядка слушателей `MaxUpdateReceived`. Чат уже скрыт
     * (список показывает только Active), так что оператор разницы не видит.
     */
    public function applyIncomingChatRemoval(Update $update): bool
    {
        $chatId = $update->chatId;

        if ($chatId === null) {
            $this->logger->log('warning', 'Incoming MAX chat removal without chat skipped.', [
                'update_type' => $update->updateType->value,
            ]);

            return false;
        }

        $model = $this->chatModel();

        /** @var MaxChat|null $existing */
        $existing = $model::query()->find($chatId);

        if ($existing === null || $existing->status !== MaxChatStatus::Active) {
            return false;
        }

        if (! $this->removeChat($chatId)) {
            return false;
        }

        MaxChatRemoved::dispatch($chatId);

        return true;
    }

    /**
     * Смена названия чата (`chat_title_changed`): значение уже есть в апдейте, так
     * что запрос к API не нужен. Название обновляется только у уже известного чата
     * — так же поступает слушатель laravel-max-client, иначе пустой список оператора
     * заполнился бы чатами без сообщений. Пустой title игнорируется, чтобы не
     * затереть уже сохранённое название.
     */
    public function applyIncomingTitle(Update $update): ?MaxChat
    {
        $chatId = $update->chatId;
        $title = $update->title === null ? '' : trim($update->title);

        if ($chatId === null || $title === '') {
            return null;
        }

        $model = $this->chatModel();

        /** @var MaxChat|null $chat */
        $chat = $model::query()->find($chatId);

        if ($chat === null) {
            return null;
        }

        $chat->forceFill(['title' => $title])->save();

        return $chat;
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
     * @param int|null $userId Собеседник; null для чата без собеседника в реестре
     *                         (канал или группа, чей состав ещё не синхронизирован)
     * @param list<StoredAttachment> $attachments
     */
    public function storeOutgoing(
        ?int $userId,
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

        // Связи с пользователями ищем LEFT JOIN, а не JOIN: у канала и группы
        // состав может быть ещё не синхронизирован, и такой чат обязан находиться
        // по тексту сообщений, а не выпадать из выдачи.
        //
        // Порядок задаётся финальным запросом ниже; здесь его нет намеренно:
        // SELECT DISTINCT ... ORDER BY колонки, которой нет в select list,
        // PostgreSQL отвергает (SQLSTATE 42P10), а сортировка chat_id всё равно
        // была бы отброшена повторной выборкой моделей.
        $chatIds = DB::table('max_chats')
            ->leftJoin('max_chat_users', 'max_chat_users.chat_id', '=', 'max_chats.chat_id')
            ->leftJoin('max_users', 'max_users.user_id', '=', 'max_chat_users.user_id')
            ->leftJoin('max_chat_messages', 'max_chat_messages.max_chat_id', '=', 'max_chats.chat_id')
            ->where('max_chats.status', MaxChatStatus::Active)
            ->where(function (\Illuminate\Database\Query\Builder $query) use ($like): void {
                $query->whereRaw('lower(max_users.first_name) LIKE lower(?) ESCAPE \'!\'', [$like])
                    ->orWhereRaw('lower(max_users.last_name) LIKE lower(?) ESCAPE \'!\'', [$like])
                    ->orWhereRaw('lower(max_chat_messages.text) LIKE lower(?) ESCAPE \'!\'', [$like]);
            })
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
            ->with(['maxChat', 'sender'])
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
            ->with(['maxChat', 'sender'])
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

    /**
     * Локальное сообщение по идентификатору MAX в рамках чата. Идентификаторы
     * сообщений уникальны внутри чата, поэтому ищем по паре «чат + message_id»:
     * один message_id из другого чата не должен править или удалять эту историю.
     */
    private function findByMaxMessageId(Update $update): ?MaxMessage
    {
        // `message_removed` присылает message_id на верхнем уровне, а
        // `message_edited` — только внутри message.body.mid (верхнего
        // message_id в спеке нет), поэтому идентификатор берём с фолбэком.
        $messageId = $update->messageId ?? $update->message?->body?->mid;
        $chatId = $update->chatId ?? $update->message?->recipient->chatId;

        if ($messageId === null || $messageId === '' || $chatId === null) {
            $this->logger->log('warning', 'Incoming MAX message change without chat or message id skipped.', [
                'update_type' => $update->updateType->value,
            ]);

            return null;
        }

        return MaxMessage::query()
            ->where('max_chat_id', $chatId)
            ->where('message_id', $messageId)
            ->first();
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

    private function upsertChat(?int $userId, int $chatId, ?User $user = null): MaxChat
    {
        if ($userId !== null && $user !== null) {
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

        if ($userId !== null) {
            $this->upsertChatUser($chatId, $userId);
        }

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
     * Локальное сообщение по паре «chat_id + message_id». Пустой `message_id`
     * дедупликации не подлежит (сообщения без идентификатора MAX приходят редко,
     * и они не должны схлопываться в одну строку).
     */
    private function findStoredMessage(int $chatId, ?string $messageId): ?MaxMessage
    {
        if ($messageId === null || $messageId === '') {
            return null;
        }

        return MaxMessage::query()
            ->where('max_chat_id', $chatId)
            ->where('message_id', $messageId)
            ->first();
    }

    /**
     * @param list<StoredAttachment> $attachments
     */
    private function createMessage(
        MaxChat $maxChat,
        ?int $userId,
        MaxMessageDirection $direction,
        MaxMessageSender $senderType,
        ?string $text,
        ?int $operatorId = null,
        ?string $messageId = null,
        array $attachments = [],
    ): MaxMessage {
        // MAX доставляет апдейты повторно и дублирует своё же событие на
        // исходящее сообщение бота, а идентификатор сообщения уникален внутри
        // чата. Поэтому уже сохранённое сообщение с той же парой
        // «chat_id + message_id» не создаём второй раз: иначе в ленте появился
        // бы дубль, а автообработка плагина поверх слушателя приложения
        // удвоила бы входящие.
        $existing = $this->findStoredMessage($maxChat->chat_id, $messageId);

        if ($existing !== null) {
            return $existing;
        }

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
