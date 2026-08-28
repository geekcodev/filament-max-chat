<?php

declare(strict_types=1);

use GeekCo\FilamentMaxChat\Models\MaxChat;

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
    | chat_model — расширение MaxChat из laravel-max-client со связями чата;
    | переопределяйте своим подклассом при необходимости (таблица max_chats).
    | user_model — модель оператора (персонала) для связи operator_id.
    |
    */

    'chat_model' => MaxChat::class,

    'user_model' => env('FILAMENT_MAX_CHAT_USER_MODEL', \Illuminate\Foundation\Auth\User::class),

    /*
    |--------------------------------------------------------------------------
    | Профиль/аватар пользователя MAX
    |--------------------------------------------------------------------------
    |
    | Ленивая фоновая подгрузка профиля (аватара) через MaxUserProfileService
    | (laravel-max-client, getChatMembers) — аватар в апдейтах не приходит.
    | prefetch: on_open (при открытии чата) | on_list (для списка бесед) | both.
    | cache_ttl — сколько (сек) не перезапрашивать профиль одного пользователя
    | (троттлинг попыток, чтобы не дёргать API на каждый poll).
    | refresh_existing — обновлять ли профиль, если аватар уже заполнен.
    |
    */

    'profile' => [
        'enabled' => filter_var(env('FILAMENT_MAX_CHAT_PROFILE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'prefetch' => env('FILAMENT_MAX_CHAT_PROFILE_PREFETCH', 'both'),
        'cache_ttl' => (int) env('FILAMENT_MAX_CHAT_PROFILE_CACHE_TTL', 86400),
        'refresh_existing' => filter_var(env('FILAMENT_MAX_CHAT_PROFILE_REFRESH_EXISTING', false), FILTER_VALIDATE_BOOLEAN),
    ],

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
    | HTTP-роуты пакета (web-мидлвара, авторизация по permissions.view)
    |--------------------------------------------------------------------------
    |
    | Отдача файлов из приватного диска и эндпоинт счётчика непрочитанного
    | (используется глобальным HTTP-poll уведомлений). enabled=false отключает
    | оба роута целиком.
    |
    */

    'route' => [
        'enabled' => filter_var(env('FILAMENT_MAX_CHAT_ROUTE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'uri' => env('FILAMENT_MAX_CHAT_ROUTE_URI', '/admin/chat/messages/{message}/attachment'),
        'login_url' => env('FILAMENT_MAX_CHAT_LOGIN_URL', '/admin/login'),
        'unread_count_uri' => env('FILAMENT_MAX_CHAT_UNREAD_COUNT_URI', '/admin/chat/unread-count'),
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
    | poll_enabled / poll_interval_seconds — HTTP-poll счётчика непрочитанного
    | как фолбэк к Echo (обновляет бейдж и уведомления на всех страницах).
    |
    */

    'notifications' => [
        'enabled' => filter_var(env('FILAMENT_MAX_CHAT_NOTIFICATIONS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'sound' => filter_var(env('FILAMENT_MAX_CHAT_NOTIFICATIONS_SOUND', true), FILTER_VALIDATE_BOOLEAN),
        'browser' => filter_var(env('FILAMENT_MAX_CHAT_NOTIFICATIONS_BROWSER', true), FILTER_VALIDATE_BOOLEAN),
        'poll_enabled' => filter_var(env('FILAMENT_MAX_CHAT_NOTIFICATIONS_POLL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'poll_interval_seconds' => (int) env('FILAMENT_MAX_CHAT_NOTIFICATIONS_POLL_INTERVAL', 15),
    ],

];
