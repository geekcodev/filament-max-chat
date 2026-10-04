<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature;

use GeekCo\FilamentMaxChat\Tests\TestCase;

/**
 * Порядок применения миграций задаётся именем файла: Laravel сортирует миграции
 * приложения и всех пакетов по имени и идёт по списку сверху вниз. Поэтому
 * внешний ключ допустим только на таблицу, чья миграция имеет меньшее имя, а
 * «полоса» в имени миграции — это позиция пакета в общем порядке, а не отдельный
 * именованный диапазон.
 *
 * На SQLite нарушение не воспроизводится: там таблица с внешним ключом на
 * несуществующую таблицу создаётся молча, ошибка всплывает только на данных. На
 * PostgreSQL и MySQL DDL падает сразу, то есть чистая установка невозможна.
 * Поэтому порядок проверяется по исходникам миграций, а не через попытку
 * migrate: регрессию, которую ловит этот тест, не видит ни один прогон тестов на
 * SQLite.
 *
 * Регрессия v1.1.0: create-миграция max_chat_messages была 0001_01_01_000001 и
 * ссылалась на max_chats, которую laravel-max-client создаёт миграцией
 * 0001_01_01_000002, то есть позже.
 *
 * Здесь проверяется контракт полосы пакета: собственные миграции лежат в
 * 0000_02, а каждая таблица, на которую они ссылаются, принадлежит строго более
 * ранней полосе. Файлы соседних пакетов в vendor не читаются — там установленная
 * версия laravel-max-client, чьи имена миграций меняются независимо от этого
 * репозитория. Сквозную проверку по реальным файлам, где виден весь стек, делает
 * tests/Feature/MigrationOrderTest в приложении.
 */
final class MigrationOrderTest extends TestCase
{
    private const BAND = '0000_02';

    /**
     * Таблицы, на которые ссылаются миграции пакета, и полосы их создания.
     *
     * @var array<string, string>
     */
    private const REFERENCED_TABLES = [
        'max_chats' => '0000_00',
        'users' => '0000_01',
    ];

    public function testMigrationsUsePackageBand(): void
    {
        foreach ($this->ownMigrationFiles() as $name) {
            $this->assertStringStartsWith(
                self::BAND,
                $name,
                sprintf('%s: миграция вне полосы %s, порядок применения может сломаться', $name, self::BAND),
            );
        }
    }

    /**
     * Каждый внешний ключ пакета ведёт в строго более раннюю полосу.
     */
    public function testForeignKeysTargetEarlierBands(): void
    {
        foreach ($this->foreignKeyTargets() as $migration => $targets) {
            foreach ($targets as $table) {
                $this->assertArrayHasKey(
                    $table,
                    self::REFERENCED_TABLES,
                    sprintf(
                        'Миграция %s ссылается на %s: полоса этой таблицы не объявлена, добавь её в MigrationOrderTest',
                        $migration,
                        $table,
                    ),
                );

                $this->assertLessThan(
                    $migration,
                    self::REFERENCED_TABLES[$table],
                    sprintf(
                        'Миграция %s ссылается на %s, но %s создаётся в полосе %s — позже или в той же',
                        $migration,
                        $table,
                        $table,
                        self::REFERENCED_TABLES[$table],
                    ),
                );
            }
        }
    }

    /**
     * Таблицы, на которые ссылаются собственные миграции: имя файла => список.
     *
     * @return array<string, list<string>>
     */
    private function foreignKeyTargets(): array
    {
        $files = [];

        foreach ($this->ownMigrationPaths() as $path) {
            $files[basename($path, '.php')] = (string) file_get_contents($path);
        }

        $targets = [];

        foreach ($files as $name => $contents) {
            preg_match_all("/->(?:on|constrained)\('([a-z_]+)'\)/", $contents, $matches);

            $tables = array_values(array_unique($matches[1]));

            if ($tables !== []) {
                $targets[$name] = $tables;
            }
        }

        return $targets;
    }

    /**
     * @return list<string>
     */
    private function ownMigrationFiles(): array
    {
        return array_map(static fn (string $path): string => basename($path, '.php'), $this->ownMigrationPaths());
    }

    /**
     * @return list<string>
     */
    private function ownMigrationPaths(): array
    {
        $directory = dirname(__DIR__, 2).'/database/migrations';

        $this->assertDirectoryExists($directory);

        return glob($directory.'/*.php') ?: [];
    }
}
