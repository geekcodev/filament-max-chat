@php use GeekCo\FilamentMaxChat\Enums\ChatMessageDirection; use GeekCo\FilamentMaxChat\Enums\ChatMessageSender; @endphp

@php($pollInterval = config('filament-max-chat.ui.poll_interval', '10s'))
@php($channel = config('filament-max-chat.broadcast_channel', 'chat.channel'))

<div
    class="flex h-[70vh] gap-4 overflow-hidden"
    data-channel="{{ $channel }}"
    wire:poll.{{ $pollInterval }}="refresh"
>
    <div class="w-80 shrink-0 overflow-y-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        @forelse ($this->conversations as $chat)
            <button
                type="button"
                wire:key="chat-{{ $chat->id }}"
                wire:click="selectChat({{ $chat->id }})"
                class="flex w-full items-start gap-3 border-b border-gray-100 px-4 py-3 text-left hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5 {{ $activeChatId === $chat->id ? 'bg-primary-50 dark:bg-primary-500/10' : '' }}"
            >
                @if ($chat->maxUser?->avatar_url)
                    <img src="{{ $chat->maxUser->avatar_url }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover">
                @else
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-200 dark:bg-white/10">
                        <span class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ mb_strtoupper(mb_substr($chat->maxUser?->first_name ?? $chat->conversationName(), 0, 1) . mb_substr($chat->maxUser?->last_name ?? '', 0, 1)) }}</span>
                    </div>
                @endif
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $chat->conversationName() }}</span>
                        @if ($chat->lastMessage?->created_at)
                            <span class="shrink-0 text-xs text-gray-400">{{ $chat->lastMessage->created_at->diffForHumans() }}</span>
                        @endif
                    </div>
                    <p class="mt-0.5 truncate text-sm text-gray-500 dark:text-gray-400">
                        {{ $chat->lastMessage?->previewText() }}
                    </p>
                </div>
                @if (($chat->unread_count ?? 0) > 0)
                    <span class="ml-2 inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary-600 px-1.5 text-xs font-semibold text-white">
                        {{ $chat->unread_count }}
                    </span>
                @endif
            </button>
        @empty
            <p class="px-4 py-8 text-center text-sm text-gray-500">{{ __('filament-max-chat::chat.empty_conversations') }}</p>
        @endforelse
    </div>

    <div class="flex min-w-0 flex-1 flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        @if ($activeChatId === null)
            <div class="flex flex-1 items-center justify-center text-sm text-gray-500">
                {{ __('filament-max-chat::chat.select_conversation') }}
            </div>
        @else
            @php($activeChat = $this->conversations->firstWhere('id', $activeChatId))
            @php($chatUser = $activeChat?->maxUser)
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2 dark:border-white/5">
                <div class="flex min-w-0 items-center gap-2" x-data="{ showUserInfo: false }">
                    @if ($chatUser?->avatar_url)
                        <img src="{{ $chatUser->avatar_url }}" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover">
                    @elseif ($chatUser)
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-200 dark:bg-white/10">
                            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ mb_strtoupper(mb_substr($chatUser->first_name, 0, 1) . mb_substr($chatUser->last_name ?? '', 0, 1)) }}</span>
                        </div>
                    @endif
                    <button type="button" @click="showUserInfo = true" class="min-w-0 truncate text-sm font-medium text-gray-950 hover:underline dark:text-white">
                        {{ $activeChat?->conversationName() }}
                    </button>

                    <div
                        x-show="showUserInfo"
                        x-cloak
                        x-transition:enter="ease-out duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                        x-transition:leave="ease-in duration-150"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
                        @click.self="showUserInfo = false"
                        @keydown.escape.window="showUserInfo = false"
                    >
                        <div
                            x-show="showUserInfo"
                            x-transition:enter="ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="ease-in duration-150"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="mx-4 w-full max-w-sm rounded-xl bg-white p-6 shadow-xl dark:bg-gray-900"
                            @click.stop
                        >
                            <div class="flex flex-col items-center gap-3">
                                @if ($chatUser?->full_avatar_url || $chatUser?->avatar_url)
                                    <img src="{{ $chatUser->full_avatar_url ?? $chatUser->avatar_url }}" alt="" class="h-20 w-20 rounded-full object-cover">
                                @elseif ($chatUser)
                                    <div class="flex h-20 w-20 items-center justify-center rounded-full bg-gray-200 dark:bg-white/10">
                                        <span class="text-2xl font-medium text-gray-600 dark:text-gray-300">{{ mb_strtoupper(mb_substr($chatUser->first_name, 0, 1) . mb_substr($chatUser->last_name ?? '', 0, 1)) }}</span>
                                    </div>
                                @endif
                                <div class="text-center">
                                    <p class="text-base font-semibold text-gray-950 dark:text-white">{{ $chatUser?->first_name }} {{ $chatUser?->last_name }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">ID: {{ $chatUser?->user_id }}</p>
                                </div>
                            </div>
                            <div class="mt-5 flex justify-center">
                                <button type="button" @click="showUserInfo = false" class="rounded-lg bg-gray-100 px-4 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20">
                                    {{ __('filament-max-chat::chat.close') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                @if ($this->canAnswer)
                    <button
                        type="button"
                        wire:click="clearChat"
                        x-data
                        x-on:click="if (! confirm('{{ __('filament-max-chat::chat.clear_confirm') }}')) { $event.preventDefault(); }"
                        class="shrink-0 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-300"
                        title="{{ __('filament-max-chat::chat.clear_history') }}"
                    >
                        <x-heroicon-o-trash class="h-4 w-4" />
                    </button>
                @endif
            </div>
            <div id="chat-messages" class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
                @if ($this->hasMoreMessages)
                    <div
                        wire:click="loadMoreMessages"
                        wire:loading.remove
                        wire:target="loadMoreMessages"
                        class="cursor-pointer py-2 text-center text-xs text-gray-400 hover:text-gray-600"
                    >
                        {{ __('filament-max-chat::chat.load_older') }}
                    </div>
                @endif
                <div wire:loading wire:target="loadMoreMessages" class="py-2 text-center text-xs text-gray-400">
                    {{ __('filament-max-chat::chat.loading') }}
                </div>
                @forelse ($this->messages as $message)
                    <div wire:key="message-{{ $message->id }}" class="group relative flex {{ $message->direction === ChatMessageDirection::Out ? 'justify-end' : 'justify-start' }}">
                        @if ($message->direction === ChatMessageDirection::In)
                            @php($userAvatar = $message->botChat?->maxUser?->avatar_url)
                            @if ($userAvatar)
                                <img src="{{ $userAvatar }}" alt="" class="mr-2 mt-1 h-8 w-8 shrink-0 rounded-full object-cover">
                            @else
                                <div class="mr-2 mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-200 dark:bg-white/10">
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ mb_strtoupper(mb_substr($message->botChat?->maxUser?->first_name ?? '?', 0, 1) . mb_substr($message->botChat?->maxUser?->last_name ?? '', 0, 1)) }}</span>
                                </div>
                            @endif
                        @endif
                        <div class="relative max-w-[75%] rounded-lg px-3 py-2 text-sm {{ $message->direction === ChatMessageDirection::Out ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' }}">
                            @if ($this->canAnswer)
                                <button
                                    type="button"
                                    wire:click="deleteMessage({{ $message->id }})"
                                    x-data
                                    x-on:click="if (! confirm('{{ __('filament-max-chat::chat.delete_confirm') }}')) { $event.preventDefault(); }"
                                    class="absolute -top-2 -right-2 z-10 flex h-5 w-5 items-center justify-center rounded-full bg-gray-200 text-gray-400 opacity-0 shadow-sm transition-opacity pointer-events-none hover:bg-red-500 hover:text-white group-hover:opacity-100 group-hover:pointer-events-auto dark:bg-gray-700 dark:text-gray-500 dark:hover:bg-red-600"
                                    title="{{ __('filament-max-chat::chat.delete_message') }}"
                                >
                                    <x-heroicon-o-trash class="h-3 w-3" />
                                </button>
                            @endif
                            @php($attachment = $message->attachment)
                            @if (is_array($attachment))
                                @php($attachmentUrl = filled($attachment['path'] ?? null) ? route('filament-max-chat.attachment', ['message' => $message]) : null)
                                @if (($attachment['type'] ?? '') === 'image')
                                    @if ($attachmentUrl !== null)
                                        <a href="{{ $attachmentUrl }}" target="_blank" rel="noopener">
                                            <img src="{{ $attachmentUrl }}" alt="{{ $attachment['name'] ?? '' }}" class="mb-1 max-h-64 w-auto rounded-lg">
                                        </a>
                                    @else
                                        <p class="italic opacity-80">{{ __('filament-max-chat::chat.preview.image_unavailable') }}</p>
                                    @endif
                                @elseif (($attachment['type'] ?? '') === 'video')
                                    @if ($attachmentUrl !== null)
                                        <video controls class="mb-1 max-h-64 w-auto rounded-lg" src="{{ $attachmentUrl }}"></video>
                                    @else
                                        <p class="italic opacity-80">{{ __('filament-max-chat::chat.preview.video') }}</p>
                                    @endif
                                @elseif (($attachment['type'] ?? '') === 'audio')
                                    @if ($attachmentUrl !== null)
                                        <audio controls class="mb-1 w-56" src="{{ $attachmentUrl }}"></audio>
                                    @else
                                        <p class="italic opacity-80">{{ __('filament-max-chat::chat.preview.audio') }}</p>
                                    @endif
                                @elseif ($attachmentUrl !== null)
                                    <a href="{{ $attachmentUrl }}" download="{{ $attachment['name'] ?? '' }}" class="underline opacity-90 hover:opacity-100">
                                        📎 {{ $attachment['name'] ?? '' }}
                                    </a>
                                @else
                                    <p class="italic opacity-80">{{ __('filament-max-chat::chat.preview.file_unavailable') }}</p>
                                @endif
                            @endif
                            @if ($message->direction === ChatMessageDirection::Out && $message->sender_type === ChatMessageSender::Operator && filled($message->text))
                                <div class="whitespace-pre-line break-words [&_a]:underline">{!! $message->text !!}</div>
                            @elseif (filled($message->text))
                                <p class="whitespace-pre-wrap break-words">{{ $message->text }}</p>
                            @endif
                            <p class="mt-1 text-xs opacity-70">
                                {{ $message->direction->label() }}
                                {{ $message->created_at?->format('H:i') }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-gray-500">{{ __('filament-max-chat::chat.empty_messages') }}</p>
                @endforelse
            </div>

            <div class="border-t border-gray-100 p-3 dark:border-white/5">
                @if ($this->canAnswer)
                    <form wire:submit="sendReply" class="space-y-2">
                        <div class="flex flex-wrap items-center gap-1">
                            @foreach ([
                                [__('filament-max-chat::chat.format_bold'), '<b>', '</b>', 'font-bold'],
                                [__('filament-max-chat::chat.format_italic'), '<i>', '</i>', 'italic'],
                                [__('filament-max-chat::chat.format_underline'), '<u>', '</u>', 'underline'],
                                [__('filament-max-chat::chat.format_strikethrough'), '<s>', '</s>', 'line-through'],
                                [__('filament-max-chat::chat.format_code'), '<code>', '</code>', 'font-mono text-[11px]'],
                            ] as [$label, $wrapStart, $wrapEnd, $labelClass])
                                <button
                                    type="button"
                                    title="{{ __('filament-max-chat::chat.formatting') }}"
                                    data-wrap-start="{{ $wrapStart }}"
                                    data-wrap-end="{{ $wrapEnd }}"
                                    class="h-7 min-w-7 rounded-md border border-gray-300 px-1.5 text-xs text-gray-700 hover:bg-gray-100 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/10 {{ $labelClass }}"
                                >{{ $label }}</button>
                            @endforeach
                            <button
                                type="button"
                                title="{{ __('filament-max-chat::chat.insert_link') }}"
                                data-wrap-start="<a href=&quot;https://&quot;>"
                                data-wrap-end="</a>"
                                class="h-7 min-w-7 rounded-md border border-gray-300 px-1.5 text-xs text-gray-700 hover:bg-gray-100 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/10"
                            >🔗</button>
                        </div>

                        <textarea
                            id="operator-reply"
                            wire:model="reply"
                            rows="3"
                            placeholder="{{ __('filament-max-chat::chat.placeholder') }}"
                            autocomplete="off"
                            class="block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        ></textarea>

                        @error('reply')
                            <p class="text-sm text-danger-600">{{ $message }}</p>
                        @enderror

                        <div
                            class="flex flex-wrap items-center gap-3"
                            x-data="{ uploading: false }"
                            x-on:livewire:upload:started.window="uploading = true"
                            x-on:livewire:upload:finished.window="uploading = false; $wire.$attachment === null && ($refs.fileInput.value = '')"
                            x-on:livewire:upload:cancelled.window="uploading = false"
                            x-on:livewire:upload:error.window="uploading = false"
                            x-on:clear-file-input.window="$refs.fileInput.value = ''"
                        >
                            <input
                                type="file"
                                wire:model="attachment"
                                x-ref="fileInput"
                                accept=".{{ str_replace(',', ',.', config('filament-max-chat.attachments.mimes')) }}"
                                class="text-sm text-gray-600 file:mr-2 file:cursor-pointer file:rounded-md file:border-0 file:bg-gray-100 file:px-2 file:py-1 file:text-xs file:font-medium file:text-gray-700 hover:file:bg-gray-200 dark:text-gray-400 dark:file:bg-white/10 dark:file:text-gray-200"
                            >
                            <span wire:loading wire:target="attachment" class="text-xs text-gray-500">{{ __('filament-max-chat::chat.uploading') }}</span>
                            @if ($attachment)
                                <button type="button" wire:click="$set('attachment', null)" x-show="!uploading" class="text-xs text-danger-600 hover:underline">
                                    {{ __('filament-max-chat::chat.remove_file') }}
                                </button>
                            @endif
                            @error('attachment')
                                <p class="text-sm text-danger-600">{{ $message }}</p>
                            @enderror
                            <x-filament::button type="submit" class="ml-auto">{{ __('filament-max-chat::chat.send') }}</x-filament::button>
                        </div>
                    </form>
                @else
                    <p class="text-center text-sm text-gray-500">{{ __('filament-max-chat::chat.no_answer_permission') }}</p>
                @endif
            </div>
        @endif
    </div>

    <script>
        if (! window.__operatorChatReady) {
            window.__operatorChatReady = true;

            window.__fmcActiveChatId = @js($activeChatId);

            const subscribe = (root) => {
                const channel = root.getAttribute('data-channel');

                if (! channel || ! window.Echo) {
                    return false;
                }

                window.Echo.private(channel)
                    .listen('.chat-message.created', () => {
                        Livewire.dispatch('chat-refresh');
                    });

                return true;
            };

            document.addEventListener('DOMContentLoaded', () => {
                const root = document.querySelector('[data-channel]');

                if (! root) {
                    return;
                }

                if (! subscribe(root)) {
                    document.addEventListener('EchoLoaded', () => subscribe(root), { once: true });
                }
            });

            let activeChatId = null;

            const scrollToBottom = () => {
                const list = document.getElementById('chat-messages');

                if (list) {
                    list.scrollTop = list.scrollHeight;
                }
            };

            const initScrollListener = (chatId) => {
                if (activeChatId === chatId) {
                    return;
                }

                activeChatId = chatId;

                const list = document.getElementById('chat-messages');

                if (! list || list.__scrollAttached) {
                    return;
                }

                list.__scrollAttached = true;

                list.addEventListener('scroll', () => {
                    if (list.scrollTop < 50 && ! list.closest('[wire\\:loading]')) {
                        @this.loadMoreMessages();
                    }
                });
            };

            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-wrap-start]');

                if (! button) {
                    return;
                }

                const textarea = document.getElementById('operator-reply');

                if (! textarea) {
                    return;
                }

                const start = textarea.selectionStart ?? textarea.value.length;
                const end = textarea.selectionEnd ?? textarea.value.length;
                const selected = textarea.value.slice(start, end);
                const opening = button.getAttribute('data-wrap-start') ?? '';
                const closing = button.getAttribute('data-wrap-end') ?? '';

                textarea.value = textarea.value.slice(0, start) + opening + selected + closing + textarea.value.slice(end);
                textarea.dispatchEvent(new Event('input'));
                textarea.focus();

                const caret = start + opening.length + selected.length;
                textarea.setSelectionRange(caret, caret);
            });

            document.addEventListener('chat-scroll-bottom', () => {
                requestAnimationFrame(scrollToBottom);
            });

            document.addEventListener('chat-active', (e) => {
                window.__fmcActiveChatId = e.detail.chatId;
            });

            document.addEventListener('livewire:update', () => {
                const list = document.getElementById('chat-messages');

                if (list) {
                    initScrollListener(list.getAttribute('wire:id'));
                }
            });
        }
    </script>
</div>
