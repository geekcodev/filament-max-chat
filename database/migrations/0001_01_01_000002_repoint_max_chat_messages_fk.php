<?php

declare(strict_types=1);

use GeekCo\FilamentMaxChat\Services\ChatMessagesSchemaRepoint;
use Illuminate\Database\Migrations\Migration;

/**
 * Первая фаза перевода max_chat_messages на форму реестра чатов v1.2.0: снять
 * FK на суррогатный max_chats.id, перенести значения в chat_id и расширить
 * колонки идентификаторов до знаковых bigInteger.
 *
 * Миграция обязана выполниться до `php artisan max:upgrade`, потому что пересборка
 * max_chats падает, пока на таблицу висит FK. `php artisan migrate` всегда идёт
 * первым, поэтому отдельная команда не нужна. На чистой установке, где реестр
 * уже в новой форме, миграция ничего не делает.
 *
 * FK сюда не возвращается: на max_chats.chat_id его нельзя создать, пока эта
 * колонка не станет первичным ключом, а первичным ключом она станет только после
 * `max:upgrade`. Вторую фазу выполняет команда `php artisan max-chat:upgrade`.
 */
return new class () extends Migration {
    public function up(): void
    {
        app(ChatMessagesSchemaRepoint::class)->remap();
    }

    /**
     * Откат значений невозможен: после `max:upgrade` колонки `max_chats.id`
     * больше нет, и обратное соответствие не восстановить. Поэтому down() ничего
     * не трогает — откатывать плагин поверх нового адаптера нельзя.
     */
    public function down(): void
    {
        //
    }
};
