<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Events\MaxMessageCreated;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
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
            direction: MaxMessageDirection::In,
            senderType: MaxMessageSender::User,
            text: $text,
            messageId: $update->messageId ?? $update->message?->body?->mid,
            attachment: $this->attachments->storeFromIncoming($update->message?->body?->attachments),
        );
    }

    /**
     * @param array{type: string, path?: string, name?: string, mime?: string, size?: int}|null $attachment
     */
    public function storeOutgoing(
        int $userId,
        int $chatId,
        ?string $text,
        MaxMessageSender $sender,
        ?int $operatorId = null,
        ?string $messageId = null,
        ?array $attachment = null,
    ): ?MaxMessage {
        $maxChat = $this->upsertChat($userId, $chatId);

        return $this->createMessage(
            maxChat: $maxChat,
            direction: MaxMessageDirection::Out,
            senderType: $sender,
            text: $text,
            operatorId: $operatorId,
            messageId: $messageId,
            attachment: $attachment,
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
            ->with(['lastMessage', 'maxUser'])
            ->orderByDesc('last_activity_at')
            ->get();
    }

    /**
     * По search-поиску `chat_id` (идентификатор чата в MAX) вернуть внутренний
     * ID записи реестра max_chats. Позволяет открывать диалог по ссылке
     * с внешних страниц: /chat?chat_id=<id в MAX>.
     */
    public function resolveInternalIdFromMaxChatId(int $maxChatId): ?int
    {
        $model = $this->chatModel();

        /** @var MaxChat|null $chat */
        $chat = $model::query()
            ->where('chat_id', $maxChatId)
            ->orderByDesc('last_activity_at')
            ->first();

        return $chat?->id;
    }

    /**
     * @return Collection<int, MaxMessage>
     */
    public function messagesFor(int $maxChatId, ?int $limit = null): Collection
    {
        $limit ??= config()->integer('filament-max-chat.ui.messages_limit', 100);

        return MaxMessage::query()
            ->where('max_chat_id', $maxChatId)
            ->with('maxChat.maxUser')
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
            ->with('maxChat.maxUser')
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
        return $model::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'chat_id' => $chatId,
            ],
            [
                'status' => MaxChatStatus::Active,
                'last_activity_at' => now(),
            ],
        );
    }

    /**
     * @param array{type: string, path?: string, name?: string, mime?: string, size?: int}|null $attachment
     */
    private function createMessage(
        MaxChat $maxChat,
        MaxMessageDirection $direction,
        MaxMessageSender $senderType,
        ?string $text,
        ?int $operatorId = null,
        ?string $messageId = null,
        ?array $attachment = null,
    ): MaxMessage {
        $maxChat->forceFill(['last_activity_at' => now()])->save();

        $message = $maxChat->messages()->create([
            'user_id' => $maxChat->user_id,
            'chat_id' => $maxChat->chat_id,
            'message_id' => $messageId,
            'direction' => $direction,
            'sender_type' => $senderType,
            'text' => $text,
            'attachment' => $attachment,
            'operator_id' => $operatorId,
        ]);

        MaxMessageCreated::dispatch($message);

        return $message;
    }
}
