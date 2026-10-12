<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Схема max_chat_messages: user_id обязан быть nullable, иначе сообщения канала
 * и группы без собеседника в реестре не сохраняются. Отдельно проверяется, что
 * аддитивная миграция не теряет индексы (SQLite пересобирает таблицу) и что она
 * обратима: down() обязан восстановить NOT NULL на данных с null в user_id.
 */
class MaxChatMessagesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_id_is_nullable_and_indexes_survive_the_migration(): void
    {
        $column = $this->column('user_id');

        $this->assertTrue($column['nullable']);

        $indexes = [];

        /** @var list<array<string, mixed>> $rawIndexes */
        $rawIndexes = Schema::getIndexes('max_chat_messages');

        foreach ($rawIndexes as $index) {
            $name = $index['name'] ?? null;

            if (is_string($name)) {
                $indexes[] = $name;
            }
        }

        $this->assertContains('max_chat_messages_max_chat_id_created_at_index', $indexes);
        $this->assertContains('max_chat_messages_user_id_chat_id_created_at_index', $indexes);
        $this->assertContains('max_chat_messages_created_at_index', $indexes);
        $this->assertContains('max_chat_messages_unread_index', $indexes);
    }

    public function test_unread_index_migration_is_reversible(): void
    {
        $this->assertTrue(Schema::hasIndex('max_chat_messages', 'max_chat_messages_unread_index'));

        $migration = $this->unreadIndexMigration();

        /** @phpstan-ignore method.notFound (методы есть у анонимного класса) */
        $migration->down();

        $this->assertFalse(Schema::hasIndex('max_chat_messages', 'max_chat_messages_unread_index'));

        /** @phpstan-ignore method.notFound (методы есть у анонимного класса) */
        $migration->up();

        $this->assertTrue(Schema::hasIndex('max_chat_messages', 'max_chat_messages_unread_index'));
    }

    public function test_unread_index_migration_does_not_fail_when_index_exists(): void
    {
        $migration = $this->unreadIndexMigration();

        /** @phpstan-ignore method.notFound (методы есть у анонимного класса) */
        $migration->up();

        $this->assertTrue(Schema::hasIndex('max_chat_messages', 'max_chat_messages_unread_index'));
    }

    public function test_message_without_user_id_is_stored_and_read(): void
    {
        $chatId = -777;

        Schema::getConnection()->table('max_chats')->insert([
            'chat_id' => $chatId,
            'status' => 'active',
            'chat_type' => 'channel',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::getConnection()->table('max_chat_messages')->insert([
            'max_chat_id' => $chatId,
            'user_id' => null,
            'chat_id' => $chatId,
            'direction' => MaxMessageDirection::In->value,
            'sender_type' => MaxMessageSender::User->value,
            'text' => 'Пост',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('max_chat_messages', [
            'chat_id' => $chatId,
            'user_id' => null,
            'text' => 'Пост',
        ]);

        $foreignKeys = [];

        /** @var list<array<string, mixed>> $rawForeignKeys */
        $rawForeignKeys = Schema::getForeignKeys('max_chat_messages');

        foreach ($rawForeignKeys as $foreignKey) {
            $table = $foreignKey['foreign_table'] ?? null;
            $columns = $foreignKey['columns'] ?? null;

            if (is_string($table) && is_array($columns)) {
                $foreignKeys[] = [$table, array_values(array_map(
                    static fn (mixed $column): string => is_scalar($column) ? (string) $column : '',
                    $columns,
                ))];
            }
        }

        $this->assertContains(['max_chats', ['max_chat_id']], $foreignKeys);
        $this->assertContains(['users', ['operator_id']], $foreignKeys);
    }

    public function test_migration_is_reversible_on_data_without_user(): void
    {

        Schema::getConnection()->table('max_chats')->insert([
            'chat_id' => -777,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::getConnection()->table('max_chat_messages')->insert([
            'max_chat_id' => -777,
            'user_id' => null,
            'chat_id' => -777,
            'direction' => MaxMessageDirection::In->value,
            'sender_type' => MaxMessageSender::User->value,
            'text' => 'Пост',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Откат и повторное наложение проверяем на самом объекте миграции:
        // migrate:rollback в Testbench откатывает последний пакет, а фикстуры
        // тестов и пакетных миграций лежат в разных пакетах.
        $migration = $this->migration();

        /** @phpstan-ignore method.notFound (методы есть у анонимного класса) */
        $migration->down();

        $this->assertFalse($this->column('user_id')['nullable']);
        $this->assertDatabaseHas('max_chat_messages', ['chat_id' => -777, 'user_id' => -777]);

        /** @phpstan-ignore method.notFound (методы есть у анонимного класса) */
        $migration->up();

        $this->assertTrue($this->column('user_id')['nullable']);
    }

    /**
     * @return Migration&object{}
     */
    private function migration(): Migration
    {
        $file = dirname(__DIR__, 2).'/database/migrations/0000_02_000002_make_max_chat_messages_user_id_nullable.php';

        $this->assertFileExists($file);

        $migration = require $file;

        $this->assertInstanceOf(Migration::class, $migration);

        /** @var Migration $migration */
        return $migration;
    }

    /**
     * @return Migration&object{}
     */
    private function unreadIndexMigration(): Migration
    {
        $file = dirname(__DIR__, 2).'/database/migrations/0000_02_000003_add_unread_index_to_max_chat_messages.php';

        $this->assertFileExists($file);

        $migration = require $file;

        $this->assertInstanceOf(Migration::class, $migration);

        /** @var Migration $migration */
        return $migration;
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $name): array
    {
        /** @var list<array<string, mixed>> $rawColumns */
        $rawColumns = Schema::getColumns('max_chat_messages');

        foreach ($rawColumns as $column) {
            if (($column['name'] ?? null) === $name) {
                return $column;
            }
        }

        $this->fail('Колонка max_chat_messages.'.$name.' не найдена.');
    }
}
