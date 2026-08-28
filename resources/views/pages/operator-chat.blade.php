<x-filament-panels::page>
    <livewire:filament-max-chat
        :chat="is_numeric(request()->query('chat')) ? (int) request()->query('chat') : null"
        :chat_id="is_numeric(request()->query('chat_id')) ? (int) request()->query('chat_id') : null"
    />
</x-filament-panels::page>
