<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\FilamentMaxChat\Enums\ChatMessageDirection;
use GeekCo\FilamentMaxChat\Enums\ChatMessageSender;
use GeekCo\FilamentMaxChat\Events\ChatMessageCreated;
use GeekCo\FilamentMaxChat\Models\BotChat;
use GeekCo\FilamentMaxChat\Models\ChatMessage;
use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChatMessageService
{
    public function __construct(
        private readonly ChatAttachmentStore $attachments,
        private readonly Logger $logger,
    ) {
    }

    public function storeIncoming(Update $update): ?ChatMessage
    {
        $user = $update->user ?? $update->message?->sender;
        $chatId = $update->chatId ?? $update->message?->recipient->chatId;

        if ($user === null || $chatId === null) {
            $this->logger->log('warning', 'Incoming MAX message without user or chat skipped.', [
                'update_type' => $update->updateType->value,
            ]);

            return null;
        }

        $botChat = $this->upsertChat($user->userId, $chatId, $user);

        $text = $update->message?->body?->text;
        $text ??= $update->message?->body?->caption;

        return $this->createMessage(
            botChat: $botChat,
            direction: ChatMessageDirection::In,
            senderType: ChatMessageSender::User,
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
        ChatMessageSender $sender,
        ?int $operatorId = null,
        ?string $messageId = null,
        ?array $attachment = null,
    ): ?ChatMessage {
        $botChat = $this->upsertChat($userId, $chatId);

        return $this->createMessage(
            botChat: $botChat,
            direction: ChatMessageDirection::Out,
            senderType: $sender,
            text: $text,
            operatorId: $operatorId,
            messageId: $messageId,
            attachment: $attachment,
        );
    }

    /**
     * @return Collection<int, BotChat>
     */
    public function conversations(): Collection
    {
        return $this->botChatModel()::query()
            ->where('status', BotChatStatus::Active)
            ->withCount([
                'messages as unread_count' => static function (Builder $query): void {
                    $query->where('direction', ChatMessageDirection::In)
                        ->whereNull('read_at');
                },
            ])
            ->with(['lastMessage', 'maxUser'])
            ->orderByDesc('last_activity_at')
            ->get();
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public function messagesFor(int $botChatId, ?int $limit = null): Collection
    {
        $limit ??= config()->integer('filament-max-chat.ui.messages_limit', 100);

        return ChatMessage::query()
            ->where('bot_chat_id', $botChatId)
            ->with('botChat.maxUser')
            ->latest()
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public function messagesBefore(int $botChatId, int $beforeMessageId, ?int $limit = null): Collection
    {
        $limit ??= config()->integer('filament-max-chat.ui.messages_limit', 100);

        return ChatMessage::query()
            ->where('bot_chat_id', $botChatId)
            ->where('id', '<', $beforeMessageId)
            ->with('botChat.maxUser')
            ->latest()
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    public function markRead(int $botChatId): void
    {
        ChatMessage::query()
            ->where('bot_chat_id', $botChatId)
            ->where('direction', ChatMessageDirection::In)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function totalUnreadCount(): int
    {
        return (int) ChatMessage::query()
            ->where('direction', ChatMessageDirection::In)
            ->whereNull('read_at')
            ->count();
    }

    public function clearHistory(int $botChatId): int
    {
        $sender = app(MaxChatSender::class);

        $messages = ChatMessage::query()
            ->where('bot_chat_id', $botChatId)
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

        return DB::table('chat_messages')
            ->where('bot_chat_id', $botChatId)
            ->delete();
    }

    public function deleteMessage(int $chatMessageId): bool
    {
        /** @var ChatMessage|null $message */
        $message = ChatMessage::query()->find($chatMessageId);

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

    /** @return class-string<BotChat> */
    private function botChatModel(): string
    {
        /** @var class-string<BotChat> */
        return config()->string('filament-max-chat.bot_chat_model', BotChat::class);
    }

    private function upsertChat(int $userId, int $chatId, ?User $user = null): BotChat
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

        $model = $this->botChatModel();

        /** @var BotChat */
        return $model::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'chat_id' => $chatId,
            ],
            [
                'status' => BotChatStatus::Active,
                'last_activity_at' => now(),
            ],
        );
    }

    /**
     * @param array{type: string, path?: string, name?: string, mime?: string, size?: int}|null $attachment
     */
    private function createMessage(
        BotChat $botChat,
        ChatMessageDirection $direction,
        ChatMessageSender $senderType,
        ?string $text,
        ?int $operatorId = null,
        ?string $messageId = null,
        ?array $attachment = null,
    ): ChatMessage {
        $botChat->forceFill(['last_activity_at' => now()])->save();

        $message = $botChat->messages()->create([
            'user_id' => $botChat->user_id,
            'chat_id' => $botChat->chat_id,
            'message_id' => $messageId,
            'direction' => $direction,
            'sender_type' => $senderType,
            'text' => $text,
            'attachment' => $attachment,
            'operator_id' => $operatorId,
        ]);

        ChatMessageCreated::dispatch($message);

        return $message;
    }
}
