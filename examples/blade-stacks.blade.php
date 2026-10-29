{{-- Пример использования Blade-стеков в хост-приложении --}}

{{-- В шапке чата, перед системными кнопками --}}
@push('filament-max-chat.header-actions-start')
    <a href="{{ route('filament.admin.resources.orders.create', ['chat_id' => $activeChat?->chat_id]) }}"
       class="shrink-0 rounded-md p-1.5 text-gray-950 hover:bg-gray-100 dark:text-white dark:hover:bg-white/10"
       title="Создать заказ">
        <x-heroicon-o-shopping-cart class="h-5 w-5" />
    </a>
@endpush

{{-- В шапке чата, после системных кнопок --}}
@push('filament-max-chat.header-actions')
    <button type="button" wire:click="$dispatch('open-crm-modal', {chatId: {{ $activeChatId }}})"
            class="shrink-0 rounded-md p-1.5 text-gray-950 hover:bg-gray-100 dark:text-white dark:hover:bg-white/10"
            title="CRM действия">
        <x-heroicon-o-briefcase class="h-5 w-5" />
    </button>
@endpush

{{-- Рядом с кнопками форматирования --}}
@push('filament-max-chat.formatting-actions')
    <a href="{{ route('filament.admin.pages.quick-replies') }}"
       class="h-7 min-w-7 rounded-md border border-gray-300 px-1.5 text-xs text-gray-700 hover:bg-gray-100 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/10"
       title="Быстрые ответы">
        <x-heroicon-o-chat-bubble-bottom-center-text class="h-4 w-4" />
    </a>
@endpush

{{-- В футере формы ответа --}}
@push('filament-max-chat.reply-footer')
    <div class="text-xs text-gray-500">
        Чат: {{ $activeChat?->displayName() }} | Пользователь: {{ $activeChat?->interlocutor()?->user_id }}
    </div>
@endpush
