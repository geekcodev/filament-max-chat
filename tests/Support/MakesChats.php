<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Support;

use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxChatUser;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\MaxPhpClient\Enum\ChatType;

/**
 * Фикстуры реестра чатов под схему v1.2.0 адаптера: в max_chats одна строка на
 * чат с первичным ключом chat_id, а пользователи чата лежат в max_chat_users.
 */
trait MakesChats
{
    /**
     * @param array<string, mixed> $attributes
     */
    protected function makeRegistryUser(int $userId, array $attributes = []): MaxUser
    {
        /** @var MaxUser */
        return MaxUser::query()->updateOrCreate(
            ['user_id' => $userId],
            array_merge([
                'first_name' => 'Иван',
                'last_name' => 'Петров',
                'is_bot' => false,
            ], $attributes),
        );
    }

    /**
     * Строка реестра max_chats. Связи с пользователями не создаёт — для этого
     * есть {@see self::linkChatUser()}.
     *
     * @param array<string, mixed> $attributes
     */
    protected function makeChat(int $chatId, array $attributes = []): MaxChat
    {
        /** @var MaxChat */
        return MaxChat::query()->create(array_merge([
            'chat_id' => $chatId,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Dialog,
            'last_activity_at' => now(),
        ], $attributes));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function linkChatUser(int $chatId, int $userId, array $attributes = []): MaxChatUser
    {
        /** @var MaxChatUser */
        return MaxChatUser::query()->create(array_merge([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'last_activity_at' => now(),
        ], $attributes));
    }

    /**
     * Чат с одним собеседником — обычный диалог оператора с пользователем.
     *
     * @param array<string, mixed> $userAttributes
     * @param array<string, mixed> $chatAttributes
     */
    protected function makeChatWithUser(
        int $chatId,
        int $userId,
        array $userAttributes = [],
        array $chatAttributes = [],
    ): MaxChat {
        $this->makeRegistryUser($userId, $userAttributes);

        $chat = $this->makeChat($chatId, $chatAttributes);

        $this->linkChatUser($chatId, $userId);

        return $chat;
    }
}
