<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История переписки оператора с пользователями MAX.
 *
 * Полоса 0000_02 задана графом внешних ключей, а не вкусом: Laravel сортирует
 * миграции приложения и всех пакетов по имени файла и идёт по списку сверху вниз,
 * поэтому FK допустим только на таблицу, создаваемую миграцией с меньшим именем.
 * Раскладка полос стека:
 *
 *   0000_00 — laravel-max-client: max_users, max_chats, max_chat_users
 *   0000_01 — приложение, системные таблицы (users)
 *   0000_02 — этот пакет: max_chat_messages
 *   0000_03 — filament-max-broadcasts
 *   0000_04 — приложение, доменные таблицы
 *
 * Отсюда две причины, по которым полосу нельзя двигать: max_chat_messages
 * ссылается на max_chats (полоса 0000_00) и на users (полоса 0000_01), поэтому
 * должна идти после обеих, но раньше filament-max-broadcasts, которому плагин
 * не нужен.
 *
 * Пара полос несамостоятельна: 0000_00 у адаптера появился вместе с переводом его
 * миграций, поэтому до выхода адаптера с полосой 0000_00 сочетание не работает и
 * чистая установка снова падает на поздней max_chats. Соседние файлы в vendor
 * читать нельзя: там установленная версия адаптера, а не то, что в стеке.
 *
 * hasTable в up() обязателен: прежнее имя
 * 0001_01_01_000001_create_max_chat_messages_table уже записано в таблицу
 * migrations у установленных приложений, поэтому после обновления новое имя
 * числится невыполненным при уже существующей таблице.
 *
 * Инвариант порядка проверяет MigrationOrderTest: на SQLite такая ошибка не
 * воспроизводится, поэтому её не видит ни один прогон тестов на migrate.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('max_chat_messages')) {
            return;
        }

        Schema::create('max_chat_messages', function (Blueprint $table): void {
            $table->id();

            $table->bigInteger('max_chat_id');
            $table->bigInteger('user_id')->comment('Идентификатор пользователя MAX');
            $table->bigInteger('chat_id')->comment('Идентификатор чата в MAX');
            $table->string('message_id')->nullable()->comment('Идентификатор сообщения в MAX');
            $table->string('direction', 8)->comment('Направление: in/out');
            $table->string('sender_type', 16)->comment('Отправитель: operator/user');
            $table->text('text')->nullable()->comment('Текст сообщения');
            $table->json('attachment')->nullable()->comment('Метаданные вложения (type/path/name/mime/size)');
            $table->foreignId('operator_id')->nullable()->comment('Оператор, отправивший ответ')->constrained('users')->nullOnDelete();
            $table->timestamp('read_at')->nullable()->comment('Время прочтения оператором');
            $table->timestamps();

            $table->foreign('max_chat_id')->references('chat_id')->on('max_chats')->cascadeOnDelete();
            $table->index(['max_chat_id', 'created_at']);
            $table->index(['user_id', 'chat_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('max_chat_messages');
    }
};
