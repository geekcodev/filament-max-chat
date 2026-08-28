<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Livewire;

use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Services\ChatProfileRefresher;
use GeekCo\FilamentMaxChat\Services\MaxAttachmentStore;
use GeekCo\FilamentMaxChat\Services\MaxChatSender;
use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use GeekCo\FilamentMaxChat\Support\TextSanitizer;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Enum\UploadType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class OperatorChat extends Component
{
    use WithFileUploads;

    public ?int $activeChatId = null;

    public string $reply = '';

    public ?TemporaryUploadedFile $attachment = null;

    /** @var Collection<int, MaxMessage> */
    public Collection $messages;

    #[Locked]
    public bool $canAnswer = false;

    private ?MaxMessageService $service = null;

    private ?TextSanitizer $sanitizer = null;

    private ?ChatProfileRefresher $profileRefresher = null;

    public function boot(): void
    {
        $this->service = app(MaxMessageService::class);
        $this->sanitizer = app(TextSanitizer::class);
        $this->profileRefresher = app(ChatProfileRefresher::class);
    }

    public function render(): View
    {
        return view('filament-max-chat::components.operator-chat');
    }

    public function mount(?int $chat = null, ?int $chat_id = null): void
    {
        $this->messages = new Collection();

        $user = auth()->user();

        abort_unless($user !== null && $user->can($this->viewPermission()), 403);

        $this->canAnswer = $user->can($this->answerPermission());

        if ($chat_id !== null) {
            $internalId = $this->service?->resolveInternalIdFromMaxChatId($chat_id);

            if ($internalId !== null) {
                $this->selectChat($internalId);

                return;
            }
        }

        if ($chat !== null) {
            $this->selectChat($chat);
        }
    }

    public function selectChat(int $chatId): void
    {
        $this->activeChatId = $chatId;

        if ($this->prefetchIncludes('on_open')) {
            $this->profileRefresher?->refreshForChat($chatId);
        }

        $this->loadMessages();
        $this->service?->markRead($chatId);
        $this->dispatchUnreadCount();
        $this->dispatch('chat-scroll-bottom');
        $this->dispatch('chat-active', chatId: $chatId);
    }

    public function loadMoreMessages(): void
    {
        if ($this->activeChatId === null || ! $this->hasMoreMessages()) {
            return;
        }

        $oldest = $this->messages->first();

        if ($oldest === null) {
            return;
        }

        $older = $this->service?->messagesBefore(
            $this->activeChatId,
            $oldest->id,
        );

        if ($older?->isNotEmpty()) {
            $this->messages = $older->concat($this->messages)->values();
        }
    }

    public function clearChat(): void
    {
        $user = auth()->user();

        abort_unless($user !== null && $user->can($this->answerPermission()), 403);

        if ($this->activeChatId === null) {
            return;
        }

        $this->service?->clearHistory($this->activeChatId);

        $this->messages = new Collection();

        $this->dispatchUnreadCount();
    }

    public function removeChat(): void
    {
        $user = auth()->user();

        abort_unless($user !== null && $user->can($this->answerPermission()), 403);

        if ($this->activeChatId === null) {
            return;
        }

        $this->service?->removeChat($this->activeChatId);

        $this->activeChatId = null;
        $this->messages = new Collection();

        $this->dispatchUnreadCount();
        $this->dispatch('chat-removed');
    }

    public function deleteMessage(int $messageId): void
    {
        $user = auth()->user();

        abort_unless($user !== null && $user->can($this->answerPermission()), 403);

        $this->service?->deleteMessage($messageId);

        $this->messages = $this->messages->reject(
            static fn (MaxMessage $m): bool => $m->id === $messageId,
        )->values();

        $this->dispatchUnreadCount();
    }

    /**
     * @return Collection<int, MaxChat>
     */
    #[Computed]
    public function conversations(): Collection
    {
        $conversations = $this->service?->conversations() ?? new Collection();

        if ($this->prefetchIncludes('on_list')) {
            $this->profileRefresher?->refreshForConversations($conversations);
        }

        return $conversations;
    }

    #[Computed]
    public function hasMoreMessages(): bool
    {
        if ($this->activeChatId === null) {
            return false;
        }

        $oldest = $this->messages->first();

        if ($oldest === null) {
            return false;
        }

        return MaxMessage::query()
            ->where('max_chat_id', $this->activeChatId)
            ->where('id', '<', $oldest->id)
            ->exists();
    }

    public function updatedAttachment(): void
    {
        $this->validateOnly('attachment', $this->rules());
    }

    public function sendReply(): void
    {
        $user = auth()->user();

        abort_unless($user !== null && $user->can($this->answerPermission()), 403);

        $this->validate($this->rules());

        if ($this->activeChatId === null) {
            return;
        }

        /** @var class-string<MaxChat> $chatModel */
        $chatModel = config()->string('filament-max-chat.chat_model');

        $chat = $chatModel::query()->findOrFail($this->activeChatId);

        $caption = trim($this->reply) !== '' ? (string) $this->sanitizer?->sanitize($this->reply) : null;
        $maxCaption = $caption !== null ? (string) $this->sanitizer?->toMaxHtml($caption) : null;
        $recipient = new Recipient(chatId: $chat->chat_id, userId: $chat->user_id);

        try {
            if ($this->attachment !== null) {
                app(MaxChatSender::class)->sendAttachment(
                    $recipient,
                    $this->uploadTypeFor($this->attachment),
                    $this->attachment->getRealPath(),
                    $maxCaption,
                );
            } else {
                app(MaxChatSender::class)->sendFormatted($recipient, (string) $maxCaption);
            }
        } catch (Throwable $exception) {
            Log::error('Operator chat: failed to send reply to MAX.', [
                'max_chat_id' => $chat->id,
                'error' => $exception->getMessage(),
            ]);
            $this->addError('reply', __('filament-max-chat::chat.send_failed'));

            return;
        }

        $attachmentMeta = $this->attachment !== null
            ? app(MaxAttachmentStore::class)->storeFromUpload($this->attachment)
            : null;

        /** @var int|string $identifier */
        $identifier = $user->getAuthIdentifier();

        $operatorId = (int) $identifier;

        $chatId = $chat->chat_id;

        if ($chatId === null) {
            return;
        }

        $this->service?->storeOutgoing(
            userId: $chat->user_id,
            chatId: $chatId,
            text: $maxCaption,
            sender: MaxMessageSender::Operator,
            operatorId: $operatorId,
            attachment: $attachmentMeta,
        );

        $this->reset('reply', 'attachment');

        $this->dispatch('clear-file-input');
        $this->loadMessages();
    }

    #[On('chat-refresh')]
    public function refresh(): void
    {
        if ($this->activeChatId === null) {
            return;
        }

        $this->service?->markRead($this->activeChatId);

        $this->dispatchUnreadCount();

        $this->appendNewMessages();
    }

    private function loadMessages(): void
    {
        if ($this->activeChatId === null) {
            $this->messages = new Collection();

            return;
        }

        $this->messages = $this->service?->messagesFor($this->activeChatId) ?? new Collection();
    }

    private function dispatchUnreadCount(): void
    {
        $this->dispatch('chat-unread', count: $this->service?->totalUnreadCount() ?? 0);
    }

    private function appendNewMessages(): void
    {
        if ($this->activeChatId === null) {
            return;
        }

        $latest = $this->messages->last();

        $newMessages = $this->service?->messagesFor($this->activeChatId);

        if ($newMessages === null || $newMessages->isEmpty()) {
            return;
        }

        if ($latest === null) {
            $this->messages = $newMessages;

            return;
        }

        $newOnly = $newMessages->filter(
            static fn (MaxMessage $message): bool => $message->id > $latest->id,
        );

        if ($newOnly->isNotEmpty()) {
            $this->messages = $this->messages->concat($newOnly)->values();
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        $mimes = (string) config()->string('filament-max-chat.attachments.mimes');
        $maxKb = config()->integer('filament-max-chat.attachments.upload_max_kb', 20480);

        return [
            'reply' => [$this->attachment === null ? 'required' : 'nullable', 'string', 'max:4096'],
            'attachment' => ['nullable', 'file', "max:{$maxKb}", "mimes:{$mimes}"],
        ];
    }

    private function uploadTypeFor(TemporaryUploadedFile $file): UploadType
    {
        $mime = (string) $file->getMimeType();

        return match (true) {
            str_starts_with($mime, 'image/') => UploadType::Image,
            str_starts_with($mime, 'video/') => UploadType::Video,
            str_starts_with($mime, 'audio/') => UploadType::Audio,
            default => UploadType::File,
        };
    }

    private function viewPermission(): string
    {
        return config()->string('filament-max-chat.permissions.view', 'chat.view');
    }

    private function answerPermission(): string
    {
        return config()->string('filament-max-chat.permissions.answer', 'chat.answer');
    }

    private function prefetchIncludes(string $trigger): bool
    {
        $prefetch = (string) config()->string('filament-max-chat.profile.prefetch', 'both');

        return $prefetch === 'both' || $prefetch === $trigger;
    }
}
