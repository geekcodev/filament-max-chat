<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Чтение внешних ключей таблицы для проверок миграции max_chat_messages.
 *
 * Общий помощник нужен трём наборам тестов: перенос значений, возврат FK и
 * команда перевода проверяют одно и то же ограничение с разных сторон.
 */
trait InspectsChatSchema
{
    /**
     * Куда указывают все внешние ключи таблицы: [['таблица', ['колонки']], ...].
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function foreignKeysOn(string $table): array
    {
        /** @var array<int, array<int|string, mixed>> $foreignKeys */
        $foreignKeys = Schema::getForeignKeys($table);

        $targets = [];

        foreach ($foreignKeys as $foreign) {
            $targets[] = $this->foreignKeyTarget($foreign);
        }

        return $targets;
    }

    /**
     * @param array<int|string, mixed> $foreign
     *
     * @return array{0: string, 1: list<string>}
     */
    private function foreignKeyTarget(array $foreign): array
    {
        $table = $foreign['foreign_table'] ?? null;
        $columns = (array) ($foreign['foreign_columns'] ?? []);

        $this->assertIsString($table);

        return [
            $table,
            array_values(array_map(
                function (mixed $column): string {
                    $this->assertIsString($column);

                    return $column;
                },
                $columns,
            )),
        ];
    }
}
