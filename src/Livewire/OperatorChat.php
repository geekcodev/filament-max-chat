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

    public string $search = '';

    /** @var list<TemporaryUploadedFile> */
    public array $attachments = [];

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
        $search = trim($this->search);

        $conversations = $search !== '' && mb_strlen($search) >= 2
            ? $this->service?->searchConversations($search) ?? new Collection()
            : $this->service?->conversations() ?? new Collection();

        if ($this->prefetchIncludes('on_list')) {
            $this->profileRefresher?->refreshForConversations($conversations);
        }

        return $conversations;
    }

    public function markHighlighted(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $search = trim($this->search);

        if ($search === '' || mb_strlen($search) < 2) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        $escaped = preg_quote($search, '/');
        $pattern = '/(' . $escaped . ')/iu';
        $html = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return (string) preg_replace_callback($pattern, static function (array $matches): string {
            return '<mark class="bg-yellow-200 dark:bg-yellow-800/60 rounded px-0.5">' . htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '</mark>';
        }, $html);
    }

    /**
     * Single conversation for the active chat header, resolved independently
     * of the search-filtered list so the header stays populated even when a
     * search excludes the currently open chat.
     */
    #[Computed]
    public function activeConversation(): ?MaxChat
    {
        if ($this->activeChatId === null) {
            return null;
        }

        $conversations = $this->service?->conversations() ?? new Collection();

        /** @var MaxChat|null $chat */
        $chat = $conversations->firstWhere('id', $this->activeChatId);

        return $chat;
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

    public function updatedAttachments(): void
    {
        $this->validateOnly('attachments', $this->rules());
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
            $attachments = $this->attachments;
            $store = app(MaxAttachmentStore::class);

            if ($attachments !== []) {
                $types = array_map(
                    fn (TemporaryUploadedFile $file): UploadType => $this->uploadTypeFor($file),
                    $attachments,
                );
                $paths = array_map(
                    static fn (TemporaryUploadedFile $file): string => (string) $file->getRealPath(),
                    $attachments,
                );

                app(MaxChatSender::class)->sendAttachments($recipient, $types, $paths, $maxCaption);
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

        $attachmentMeta = array_map(
            static fn (TemporaryUploadedFile $file): array => $store->storeFromUpload($file),
            $this->attachments,
        );

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
            attachments: $attachmentMeta,
        );

        $this->reset('reply', 'attachments');

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
            'reply' => [$this->attachments === [] ? 'required' : 'nullable', 'string', 'max:4096'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', "max:{$maxKb}", "mimes:{$mimes}"],
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
