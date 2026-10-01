<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Перевод max_chat_messages.max_chat_id со суррогатного max_chats.id на
 * chat_id — форму реестра чатов laravel-max-client v1.2.0 (одна строка на чат
 * вместо строки на пару «пользователь + чат»).
 *
 * Перенос разбит на две фазы, и это не деление ради порядка в коде, а требование
 * СУБД: FK на max_chats.chat_id нельзя создать, пока в max_chats эта колонка не
 * уникальна, то есть пока реестр не пересобран, а пересобрать его (`max:upgrade`)
 * нельзя, пока висит FK из max_chat_messages. Значения при этом переносить надо
 * до пересборки: соответствие «старый id → chat_id» после неё исчезает, потому
 * что колонка id уходит.
 *
 * Первая фаза — remap() — снимает FK, переносит значения и расширяет колонки до
 * знаковых bigInteger. Вторая — repin() — возвращает FK на max_chats.chat_id.
 * Фазы идемпотентны: каждая смотрит на фактическое состояние схемы и ничего не
 * делает, если её цель уже достигнута.
 *
 * Идентификаторы MAX знаковые: у групп и каналов chat_id отрицательный, поэтому
 * колонки идентификаторов объявляются bigInteger без unsigned.
 */
final class ChatMessagesSchemaRepoint
{
    /**
     * FK снимается по списку колонок, а не по имени: SQLite не умеет удалять
     * ограничение по имени, но умеет по колонке (пересборкой таблицы). Имя,
     * которое из этого получается, — стандартное
     * max_chat_messages_max_chat_id_foreign, то есть то же, что и у MySQL и
     * PostgreSQL.
     */
    private const FK_COLUMN = 'max_chat_id';

    private const MAP_TABLE = 'max_chat_messages_id_map';

    /**
     * Реестр чатов ещё в старой форме: FK указывает на суррогатный max_chats.id.
     */
    public function needsRemap(): bool
    {
        return $this->foreignTarget() === 'id';
    }

    /**
     * FK не вернулся после пересборки реестра: max_chats уже в форме v1.2.0, а
     * ограничения на max_chat_messages нет.
     */
    public function needsRepin(): bool
    {
        if (! Schema::hasTable('max_chat_messages') || ! Schema::hasTable('max_chats')) {
            return false;
        }

        return $this->foreignTarget() === null;
    }

    /**
     * Первая фаза: снять FK, перенести значения, расширить колонки.
     *
     * FK не возвращается — до пересборки реестра вернуть его не на что.
     */
    public function remap(): void
    {
        if (! $this->needsRemap()) {
            return;
        }

        $this->dropChatForeignKey();

        $this->remapValues();
        $this->widenColumns();
    }

    /**
     * Вторая фаза: вернуть FK на max_chats.chat_id, если реестр уже пересобран.
     */
    public function repin(): void
    {
        if (! $this->needsRepin()) {
            return;
        }

        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->foreign(self::FK_COLUMN)->references('chat_id')->on('max_chats')->cascadeOnDelete();
        });
    }

    /**
     * Имя колонки в max_chats, на которую указывает FK, либо null, если такого
     * ограничения нет.
     */
    private function foreignTarget(): ?string
    {
        /** @var array<int, array{foreign_table?: string, columns?: array<int, string>, foreign_columns?: array<int, string>}> $foreignKeys */
        $foreignKeys = Schema::getForeignKeys('max_chat_messages');

        foreach ($foreignKeys as $foreign) {
            $foreignColumns = $foreign['foreign_columns'] ?? [];

            if (($foreign['foreign_table'] ?? null) === 'max_chats'
                && ($foreign['columns'] ?? null) === [self::FK_COLUMN]
                && count($foreignColumns) === 1
            ) {
                return $foreignColumns[0];
            }
        }

        return null;
    }

    private function dropChatForeignKey(): void
    {
        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->dropForeign([self::FK_COLUMN]);
        });
    }

    /**
     * Одним запросом заменить суррогатные идентификаторы на chat_id по таблице
     * соответствия. Подзапрос читает временную таблицу, а не саму
     * max_chat_messages, поэтому совпадения значений не мешают друг другу:
     * поэтапный перенос по одной строке на PHP перезаписал бы строку, если бы
     * чей-то chat_id совпал с ещё не обработанным суррогатным id.
     *
     * UPDATE ... FROM здесь не подходит: его не поддерживает MySQL.
     */
    private function remapValues(): void
    {
        Schema::dropIfExists(self::MAP_TABLE);

        Schema::create(self::MAP_TABLE, function (Blueprint $table): void {
            $table->bigInteger('legacy_id')->unique();
            $table->bigInteger('chat_id');
        });

        DB::table(self::MAP_TABLE)->insertUsing(
            ['legacy_id', 'chat_id'],
            DB::table('max_chats')->select('id', 'chat_id'),
        );

        DB::statement(
            'update ' . $this->wrap('max_chat_messages')
            . ' set max_chat_id = (select m.chat_id from ' . $this->wrap(self::MAP_TABLE)
            . ' as m where m.legacy_id = ' . $this->wrap('max_chat_messages') . '.max_chat_id)'
            . ' where max_chat_id in (select m.legacy_id from ' . $this->wrap(self::MAP_TABLE) . ' as m)',
        );

        Schema::drop(self::MAP_TABLE);
    }

    /**
     * Идентификаторы MAX не помещаются в беззнаковый INT и могут быть
     * отрицательными, поэтому колонки переводятся в знаковый bigInteger.
     */
    private function widenColumns(): void
    {
        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->bigInteger(self::FK_COLUMN)->nullable(false)->change();
            $table->bigInteger('chat_id')->nullable(false)->change();
            $table->bigInteger('user_id')->nullable(false)->change();
        });
    }

    private function wrap(string $table): string
    {
        return DB::connection()->getQueryGrammar()->wrapTable($table);
    }
}
