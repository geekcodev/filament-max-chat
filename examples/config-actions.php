<?php

declare(strict_types=1);

// Пример настройки в ServiceProvider хост-приложения
// или в config/filament-max-chat.php с array_replace_recursive

use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;

return [
    'actions' => [
        // Кнопки в шапке чата (рядом с "Удалить чат"/"Очистить историю")
        'header' => [
            [
                'label' => 'Создать заявку',
                'icon' => 'heroicon-o-document-plus',
                'class' => 'shrink-0 rounded-md p-1.5 text-gray-950 hover:bg-primary-100 hover:text-primary-600 dark:text-white dark:hover:bg-primary-950 dark:hover:text-primary-400',
                'url' => function (?MaxChat $chat, ?MaxUser $chatUser) {
                    if ($chat === null) {
                        return '#';
                    }

                    return route('filament.admin.resources.leads.create', [
                        'chat_id' => $chat->chat_id,
                        'user_id' => $chatUser?->user_id,
                        'name' => trim(($chatUser?->first_name ?? '').' '.($chatUser?->last_name ?? '')),
                        'phone' => $chatUser?->phone,
                        'email' => $chatUser?->email,
                    ]);
                },
                'visible' => function (?MaxChat $chat, ?MaxUser $chatUser) {
                    return $chat !== null && $chatUser !== null;
                },
                'permission' => 'leads.create',
            ],
            [
                'label' => 'Открыть карточку клиента',
                'icon' => 'heroicon-o-user-circle',
                'url' => function (?MaxChat $chat, ?MaxUser $chatUser) {
                    if ($chatUser?->user_id === null) {
                        return '#';
                    }

                    return route('filament.admin.resources.clients.edit', [
                        'record' => $chatUser->user_id,
                    ]);
                },
                'visible' => function (?MaxChat $chat, ?MaxUser $chatUser) {
                    return $chatUser?->user_id !== null;
                },
                'permission' => 'clients.view',
                'open_new_tab' => true,
            ],
        ],

        // Кнопки рядом с кнопками форматирования сообщения
        'formatting' => [
            // Можно добавить свои кнопки, помимо системной "Запросить контакт"
            [
                'label' => 'Шаблон',
                'class' => 'h-7 rounded-md border border-gray-300 px-2 text-xs text-gray-700 hover:bg-gray-100 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/10',
                'url' => function (?MaxChat $chat, ?MaxUser $chatUser) {
                    return route('filament.admin.pages.message-templates', [
                        'chat_id' => $chat?->chat_id,
                    ]);
                },
                'permission' => 'chat.answer',
            ],
        ],
    ],
];
