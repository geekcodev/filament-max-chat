<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Console;

use GeekCo\FilamentMaxChat\Services\ChatMessagesSchemaRepoint;
use GeekCo\LaravelMaxClient\Services\MaxSchemaUpgrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Перевод истории переписки на форму реестра чатов laravel-max-client v1.2.0.
 *
 * Команда нужна проектам, у которых пакет стоял до v1.1.0 плагина. Create-миграции
 * переписаны, но у потребителя, который их уже выполнял, имена записаны в таблице
 * migrations, поэтому изменённые файлы до него не доходят. Без этой команды FK из
 * max_chat_messages остался бы на суррогатном max_chats.id, и `max:upgrade` не смог
 * бы пересобрать реестр.
 *
 * Команда идемпотентна: на новой схеме ей нечего делать, на старой она переносит
 * значения и возвращает FK, повторный запуск ничего не меняет. Порядок шагов внутри
 * обязателен и продиктован ограничениями СУБД: перенос значений идёт до
 * `max:upgrade` (соответствие «старый id → chat_id» после пересборки исчезает, так
 * как колонка id уходит), а возврат FK — после (на max_chats.chat_id его нельзя
 * создать, пока эта колонка не станет первичным ключом).
 *
 * Таблица max_chat_users создаётся миграцией адаптера, поэтому перед переводом
 * нужен `php artisan migrate`.
 */
final class MaxChatUpgradeCommand extends Command
{
    protected $signature = 'max-chat:upgrade';

    protected $description = 'Перевести max_chat_messages на форму реестра чатов v1.2.0: max_chat_id → chat_id';

    public function handle(ChatMessagesSchemaRepoint $repoint): int
    {
        if (! Schema::hasTable('max_chat_messages')) {
            $this->error('Нет таблицы max_chat_messages. Сначала выполните `php artisan migrate`.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('max_chat_users')) {
            $this->error('Нет таблицы max_chat_users. Сначала выполните `php artisan migrate`.');

            return self::FAILURE;
        }

        if (! class_exists(MaxSchemaUpgrade::class)) {
            $this->error('Команда max:upgrade недоступна: требуется geekcodev/laravel-max-client ^1.2.0.');

            return self::FAILURE;
        }

        if ($repoint->needsRemap()) {
            $this->components->task(
                'Перенос max_chat_messages.max_chat_id со суррогатного id на chat_id',
                static function () use ($repoint): bool {
                    $repoint->remap();

                    return true;
                },
            );
        }

        $this->line('Пересборка реестра чатов адаптером (max:upgrade).');

        $output = new BufferedOutput();

        if (Artisan::call('max:upgrade', ['--force' => true], $output) !== self::SUCCESS) {
            $reported = trim($output->fetch());

            $this->error($reported === ''
                ? 'Команда max:upgrade завершилась с ошибкой.'
                : $reported);

            return self::FAILURE;
        }

        if ($repoint->needsRepin()) {
            $this->components->task(
                'Возврат FK max_chat_messages.max_chat_id на max_chats.chat_id',
                static function () use ($repoint): bool {
                    $repoint->repin();

                    return true;
                },
            );
        }

        $this->components->info('История переписки приведена к форме v1.2.0.');

        return self::SUCCESS;
    }
}
