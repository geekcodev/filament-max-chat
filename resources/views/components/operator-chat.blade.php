@php use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection; use GeekCo\FilamentMaxChat\Enums\MaxMessageSender; @endphp

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
                    <div class="flex shrink-0 items-center gap-1">
                        <button
                            type="button"
                            wire:click="removeChat"
                            wire:confirm="{{ __('filament-max-chat::chat.remove_chat_confirm') }}"
                            class="shrink-0 rounded-md p-1.5 text-gray-950 hover:bg-red-100 hover:text-red-600 dark:text-white dark:hover:bg-red-950 dark:hover:text-red-400"
                            title="{{ __('filament-max-chat::chat.remove_chat') }}"
                        >
                            <x-heroicon-o-x-circle class="h-5 w-5" />
                        </button>
                        <button
                            type="button"
                            wire:click="clearChat"
                            wire:confirm="{{ __('filament-max-chat::chat.clear_confirm') }}"
                            class="shrink-0 rounded-md p-1.5 text-gray-950 hover:bg-red-100 hover:text-red-600 dark:text-white dark:hover:bg-red-950 dark:hover:text-red-400"
                            title="{{ __('filament-max-chat::chat.clear_history') }}"
                        >
                            <x-heroicon-o-trash class="h-5 w-5" />
                        </button>
                    </div>
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
                    <div wire:key="message-{{ $message->id }}" class="group relative flex {{ $message->direction === MaxMessageDirection::Out ? 'justify-end' : 'justify-start' }}">
                        @if ($message->direction === MaxMessageDirection::In)
                            @php($userAvatar = $message->maxChat?->maxUser?->avatar_url)
                            @if ($userAvatar)
                                <img src="{{ $userAvatar }}" alt="" class="mr-2 mt-1 h-8 w-8 shrink-0 rounded-full object-cover">
                            @else
                                <div class="mr-2 mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-200 dark:bg-white/10">
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ mb_strtoupper(mb_substr($message->maxChat?->maxUser?->first_name ?? '?', 0, 1) . mb_substr($message->maxChat?->maxUser?->last_name ?? '', 0, 1)) }}</span>
                                </div>
                            @endif
                        @endif
                        <div class="relative max-w-[75%] rounded-lg px-3 py-2 text-sm {{ $message->direction === MaxMessageDirection::Out ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' }}">
                            @if ($this->canAnswer)
                                <button
                                    type="button"
                                    wire:click="deleteMessage({{ $message->id }})"
                                    wire:confirm="{{ __('filament-max-chat::chat.delete_confirm') }}"
                                    class="absolute -top-2 -right-2 z-10 flex h-5 w-5 items-center justify-center rounded-full bg-gray-200 text-gray-400 opacity-0 shadow-sm transition-opacity pointer-events-none hover:bg-red-500 hover:text-white group-hover:opacity-100 group-hover:pointer-events-auto dark:bg-gray-700 dark:text-gray-500 dark:hover:bg-red-600"
                                    title="{{ __('filament-max-chat::chat.delete_message') }}"
                                >
                                    <x-heroicon-o-trash class="h-3 w-3" />
                                </button>
                            @endif
                            @foreach ($message->attachments() as $index => $attachment)
                                @php($attachmentUrl = filled($attachment['path'] ?? null) ? route('filament-max-chat.attachment', ['message' => $message, 'index' => $index]) : null)
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
                                    <a href="{{ $attachmentUrl }}" download="{{ $attachment['name'] ?? '' }}" class="inline-flex items-center gap-1 underline opacity-90 hover:opacity-100">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline h-4 w-4">
                                            <path d="M20.4 11.7 12.6 19.5a4 4 0 0 1-5.7-5.7l7.3-7.3a2.8 2.8 0 0 1 4 4l-7.3 7.3a1.6 1.6 0 0 1-2.3-2.3l6.4-6.4"></path>
                                        </svg>
                                        {{ $attachment['name'] ?? '' }}
                                    </a>
                                @else
                                    <p class="italic opacity-80">{{ __('filament-max-chat::chat.preview.file_unavailable') }}</p>
                                @endif
                            @endforeach
                            @if ($message->direction === MaxMessageDirection::Out && $message->sender_type === MaxMessageSender::Operator && filled($message->text))
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
                    <form wire:submit="sendReply" class="space-y-2" x-data="fmcReplyEditor()">
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
                                data-link
                                title="{{ __('filament-max-chat::chat.insert_link') }}"
                                data-wrap-start="<a href=&quot;https://&quot;>"
                                data-wrap-end="</a>"
                                class="h-7 min-w-7 rounded-md border border-gray-300 px-1.5 text-xs text-gray-700 hover:bg-gray-100 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/10"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                                    <path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"></path>
                                    <path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"></path>
                                </svg>
                            </button>
                            <button
                                type="button"
                                x-on:click="togglePreview()"
                                class="ml-auto h-7 rounded-md border border-gray-300 px-2 text-xs text-gray-700 hover:bg-gray-100 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/10"
                            >
                                <span x-show="!preview" class="inline-flex items-center gap-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5">
                                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                    {{ __('filament-max-chat::chat.preview_toggle') }}
                                </span>
                                <span x-show="preview" class="inline-flex items-center gap-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5">
                                        <path d="m16 18 6-6-6-6"></path>
                                        <path d="m8 6-6 6 6 6"></path>
                                    </svg>
                                    {{ __('filament-max-chat::chat.source_toggle') }}
                                </span>
                            </button>
                        </div>

                        <textarea
                            id="operator-reply"
                            x-ref="replyTextarea"
                            wire:model="reply"
                            x-on:input="raw = $event.target.value"
                            x-show="!preview"
                            rows="3"
                            placeholder="{{ __('filament-max-chat::chat.placeholder') }}"
                            autocomplete="off"
                            class="block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white"
                        ></textarea>

                        <div
                            x-show="preview"
                            x-html="sanitizedPreview"
                            class="block min-h-20 w-full whitespace-pre-wrap break-words rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white [&_a]:underline [&_code]:rounded [&_code]:bg-gray-100 [&_code]:px-1 [&_pre]:whitespace-pre-wrap"
                        ></div>

                        @error('reply')
                            <p class="text-sm text-danger-600">{{ $message }}</p>
                        @enderror

                        <div
                            class="flex flex-col gap-2"
                            x-data="fmcFilePicker()"
                            x-on:livewire:upload:started.window="uploading = true"
                            x-on:livewire:upload:finished.window="uploading = false; syncUploaded()"
                            x-on:livewire:upload:cancelled.window="uploading = false"
                            x-on:livewire:upload:error.window="uploading = false"
                            x-on:clear-file-input.window="reset()"
                        >
                            <div class="flex flex-wrap items-center gap-3">
                                <input
                                    type="file"
                                    wire:model="attachments"
                                    multiple
                                    x-ref="fileInput"
                                    x-on:change="onFilesSelected($event)"
                                    accept=".{{ str_replace(',', ',.', config('filament-max-chat.attachments.mimes')) }}"
                                    class="text-sm text-gray-600 file:mr-2 file:cursor-pointer file:rounded-md file:border-0 file:bg-gray-100 file:px-2 file:py-1 file:text-xs file:font-medium file:text-gray-700 hover:file:bg-gray-200 dark:text-gray-400 dark:file:bg-white/10 dark:file:text-gray-200"
                                >
                                <span wire:loading wire:target="attachments" class="text-xs text-gray-500">{{ __('filament-max-chat::chat.uploading') }}</span>
                                <x-filament::button type="submit" x-show="!uploading" class="ml-auto">{{ __('filament-max-chat::chat.send') }}</x-filament::button>
                            </div>

                            <div x-show="selected.length > 0" class="grid max-h-44 grid-cols-1 gap-2 overflow-y-auto sm:grid-cols-2">
                                <template x-for="(item, index) in selected" :key="item.id">
                                    <div class="flex items-center gap-2 rounded-lg border border-gray-200 p-2 dark:border-white/10">
                                        <img
                                            x-show="item.isImage"
                                            :src="item.url"
                                            :alt="item.name"
                                            class="h-10 w-10 shrink-0 rounded object-cover"
                                        >
                                        <div x-show="!item.isImage" class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-white/10">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-gray-500 dark:text-gray-400">
                                                <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                            </svg>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-xs font-medium text-gray-700 dark:text-gray-200" x-text="item.name"></p>
                                            <p class="text-xs text-gray-400" x-text="item.size"></p>
                                        </div>
                                        <button
                                            type="button"
                                            x-on:click="removeFile(index)"
                                            x-show="!uploading"
                                            class="text-xs text-danger-600 hover:underline"
                                        >
                                            {{ __('filament-max-chat::chat.remove_file') }}
                                        </button>
                                    </div>
                                </template>
                            </div>

                            @error('attachments')
                                <p class="text-sm text-danger-600">{{ $message }}</p>
                            @enderror
                            @error('attachments.*')
                                <p class="text-sm text-danger-600">{{ $message }}</p>
                            @enderror
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
            window.__fmcInsertLinkPrompt = @js(__('filament-max-chat::chat.insert_link_prompt'));

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
                let opening = button.getAttribute('data-wrap-start') ?? '';
                const closing = button.getAttribute('data-wrap-end') ?? '';

                if (button.hasAttribute('data-link')) {
                    const url = window.prompt(window.__fmcInsertLinkPrompt || 'URL');

                    if (url === null) {
                        return;
                    }

                    opening = '<a href="' + url + '">';
                }

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

            window.fmcFormatBytes = (bytes) => {
                if (bytes === null || bytes === undefined) {
                    return '';
                }

                if (bytes < 1024) {
                    return bytes + ' B';
                }

                if (bytes < 1024 * 1024) {
                    return (bytes / 1024).toFixed(1) + ' KB';
                }

                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            };

            window.__fmcSeq = 0;

            window.fmcSanitizeHtml = (html) => {
                const allowed = new Set(['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ins', 's', 'del', 'code', 'pre', 'mark', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'a']);
                const dropWithContent = new Set(['script', 'style', 'iframe', 'object', 'embed', 'noscript']);
                const safeSchemes = ['http:', 'https:', 'max:'];

                const template = document.createElement('template');
                template.innerHTML = html || '';

                const clean = (node) => {
                    Array.from(node.childNodes).forEach((child) => {
                        if (child.nodeType !== 1) {
                            return;
                        }

                        clean(child);

                        const tag = child.tagName.toLowerCase();

                        if (dropWithContent.has(tag)) {
                            child.remove();

                            return;
                        }

                        if (! allowed.has(tag)) {
                            child.replaceWith(...Array.from(child.childNodes));

                            return;
                        }

                        Array.from(child.attributes).forEach((attribute) => {
                            if (tag === 'a' && attribute.name === 'href'
                                && safeSchemes.some((scheme) => (attribute.value || '').toLowerCase().startsWith(scheme))) {
                                return;
                            }

                            child.removeAttribute(attribute.name);
                        });
                    });
                };

                clean(template.content);

                return template.innerHTML;
            };

            window.fmcReplyEditor = () => ({
                preview: false,
                raw: '',

                get sanitizedPreview() {
                    return window.fmcSanitizeHtml(this.raw);
                },

                togglePreview() {
                    this.preview = !this.preview;

                    if (this.preview && this.$refs.replyTextarea) {
                        this.raw = this.$refs.replyTextarea.value;
                    }
                },
            });

            window.fmcFilePicker = () => ({
                selected: [],
                uploading: false,

                onFilesSelected(event) {
                    const files = Array.from(event.target.files || []);

                    files.forEach((file) => {
                        const isImage = file.type.startsWith('image/');

                        this.selected.push({
                            id: ++window.__fmcSeq,
                            name: file.name,
                            size: window.fmcFormatBytes(file.size),
                            isImage,
                            url: isImage ? URL.createObjectURL(file) : null,
                            file,
                        });
                    });
                },

                syncUploaded() {
                },

                removeFile(index) {
                    const item = this.selected[index];

                    if (! item) {
                        return;
                    }

                    if (item.url) {
                        URL.revokeObjectURL(item.url);
                    }

                    this.selected.splice(index, 1);

                    this.$wire.set('attachments', this.selected.map((entry) => entry.file));
                },

                reset() {
                    this.selected.forEach((item) => {
                        if (item.url) {
                            URL.revokeObjectURL(item.url);
                        }
                    });

                    this.selected = [];

                    if (this.$refs.fileInput) {
                        this.$refs.fileInput.value = '';
                    }
                },
            });
        }
    </script>
</div>
