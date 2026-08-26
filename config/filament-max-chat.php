<?php

declare(strict_types=1);

use GeekCo\FilamentMaxChat\Models\BotChat;

return [

    /*
    |--------------------------------------------------------------------------
    | Права доступа
    |--------------------------------------------------------------------------
    |
    | Строки прав проверяются через $user->can(...) — совместимо со spatie/laravel-permission,
    | Gate::define и любым другим провайдером способностей.
    |
    */

    'permissions' => [
        'view' => env('FILAMENT_MAX_CHAT_PERMISSION_VIEW', 'chat.view'),
        'answer' => env('FILAMENT_MAX_CHAT_PERMISSION_ANSWER', 'chat.answer'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcast
    |--------------------------------------------------------------------------
    |
    | Private-канал, на который транслируется событие нового сообщения
    | (Reverb/Soketi/Pusher). Клиент подписывается через Echo в представлении.
    |
    */

    'broadcast_channel' => env('FILAMENT_MAX_CHAT_CHANNEL', 'chat.channel'),

    /*
    |--------------------------------------------------------------------------
    | Модели
    |--------------------------------------------------------------------------
    |
    | bot_chat_model — расширение BotChat из laravel-max-client со связями чата;
    | переопределяйте своим подклассом при необходимости (таблица max_bot_chats).
    | user_model — модель оператора (персонала) для связи operator_id.
    |
    */

    'bot_chat_model' => BotChat::class,

    'user_model' => env('FILAMENT_MAX_CHAT_USER_MODEL', \Illuminate\Foundation\Auth\User::class),

    /*
    |--------------------------------------------------------------------------
    | Вложения
    |--------------------------------------------------------------------------
    |
    | Приватный диск: файлы отдаются только через авторизованный роут.
    | upload_max_kb — ограничение загрузки оператором (Livewire),
    | max_bytes — жёсткий лимит при скачивании вложений из MAX.
    |
    */

    'attachments' => [
        'disk' => env('FILAMENT_MAX_CHAT_DISK', 'local'),
        'directory' => env('FILAMENT_MAX_CHAT_DIRECTORY', 'chat-attachments'),
        'max_bytes' => (int) env('FILAMENT_MAX_CHAT_MAX_BYTES', 25 * 1024 * 1024),
        'upload_max_kb' => (int) env('FILAMENT_MAX_CHAT_UPLOAD_MAX_KB', 20480),
        'mimes' => env('FILAMENT_MAX_CHAT_MIMES', 'jpg,jpeg,png,gif,webp,bmp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,mp4,mov,mp3,wav,zip'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Роут вложений
    |--------------------------------------------------------------------------
    |
    | Отдача файлов из приватного диска (web-мидлвара, авторизация по permissions.view).
    |
    */

    'route' => [
        'enabled' => filter_var(env('FILAMENT_MAX_CHAT_ROUTE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'uri' => env('FILAMENT_MAX_CHAT_ROUTE_URI', '/admin/chat/messages/{message}/attachment'),
        'login_url' => env('FILAMENT_MAX_CHAT_LOGIN_URL', '/admin/login'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Интерфейс страницы /admin/chat (slug настраивается)
    |--------------------------------------------------------------------------
    */

    'ui' => [
        'navigation_group' => null,
        'navigation_icon' => 'heroicon-o-chat-bubble-left-right',
        'navigation_sort' => 30,
        'navigation_label' => null,
        'title' => null,
        'slug' => 'chat',
        'poll_interval' => '10s',
        'messages_limit' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Уведомления
    |--------------------------------------------------------------------------
    |
    | Глобальные оповещения о новых сообщениях на любой странице админки.
    | browser — browser notification (стандартный Notification API);
    | sound   — звуковой сигнал через Web Audio API.
    |
    */

    'notifications' => [
        'enabled' => filter_var(env('FILAMENT_MAX_CHAT_NOTIFICATIONS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'sound' => filter_var(env('FILAMENT_MAX_CHAT_NOTIFICATIONS_SOUND', true), FILTER_VALIDATE_BOOLEAN),
        'browser' => filter_var(env('FILAMENT_MAX_CHAT_NOTIFICATIONS_BROWSER', true), FILTER_VALIDATE_BOOLEAN),
    ],

];
