<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature;

use Closure;
use GeekCo\FilamentMaxChat\Tests\Support\InspectsChatSchema;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Первая фаза перевода max_chat_messages (миграция 0001_01_01_000002): снятие FK
 * на суррогатный max_chats.id, перенос значений в chat_id и расширение колонок.
 *
 * Здесь воссоздаётся старая схема — как на установке laravel-max-client v1.1.x —
 * и проверяется перенос значений, снятие ограничения и ширина колонок.
 */
class RepointMaxChatMessagesFkTest extends TestCase
{
    use InspectsChatSchema;
    use RefreshDatabase;

    /**
     * RefreshDatabase прогоняет миграции пакета в их текущем виде (уже с новой
     * схемой), а тесту нужна старая. Таблицы сносятся, чтобы собрать нужную
     * форму с нуля.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('max_chat_messages');
        Schema::dropIfExists('max_chat_messages_id_map');
        Schema::dropIfExists('max_chat_users');
        Schema::dropIfExists('max_chats');
    }

    #[Test]
    public function it_remaps_surrogate_ids_to_chat_ids(): void
    {
        $this->setUpLegacySchema();

        DB::table('max_chats')->insert([
            // Совпадение значений: chat_id первого чата равен суррогатному id второго.
            ['id' => 1, 'user_id' => 10, 'chat_id' => 2, 'status' => 'active'],
            ['id' => 2, 'user_id' => 11, 'chat_id' => 555, 'status' => 'active'],
            ['id' => 3, 'user_id' => 12, 'chat_id' => -777, 'status' => 'active'],
        ]);

        DB::table('max_chat_messages')->insert([
            ['max_chat_id' => 1, 'user_id' => 10, 'chat_id' => 2, 'text' => 'коллизия значений'],
            ['max_chat_id' => 2, 'user_id' => 11, 'chat_id' => 555, 'text' => 'диалог'],
            ['max_chat_id' => 3, 'user_id' => 12, 'chat_id' => -777, 'text' => 'группа'],
        ]);

        $this->repointUp();

        $this->assertSame(
            [2, 555, -777],
            DB::table('max_chat_messages')->orderBy('id')->pluck('max_chat_id')->all(),
        );
    }

    #[Test]
    public function it_drops_the_legacy_foreign_key(): void
    {
        $this->setUpLegacySchema();

        DB::table('max_chats')->insert(['id' => 1, 'user_id' => 10, 'chat_id' => 555, 'status' => 'active']);
        DB::table('max_chat_messages')->insert([
            ['max_chat_id' => 1, 'user_id' => 10, 'chat_id' => 555, 'text' => 'привет'],
        ]);

        $this->repointUp();

        $this->assertSame([], $this->foreignKeysOn('max_chat_messages'));
    }

    #[Test]
    public function it_drops_the_temporary_mapping_table(): void
    {
        $this->setUpLegacySchema();

        DB::table('max_chats')->insert(['id' => 1, 'user_id' => 10, 'chat_id' => 555, 'status' => 'active']);

        $this->repointUp();

        $this->assertFalse(Schema::hasTable('max_chat_messages_id_map'));
    }

    #[Test]
    public function it_allows_negative_identifiers(): void
    {
        $this->setUpLegacySchema();

        DB::table('max_chats')->insert(['id' => 1, 'user_id' => 10, 'chat_id' => 555, 'status' => 'active']);
        DB::table('max_chat_messages')->insert([
            ['max_chat_id' => 1, 'user_id' => 10, 'chat_id' => 555, 'text' => 'привет'],
        ]);

        $this->repointUp();

        // Проверяется не имя типа (оно различается по СУБД), а то, что колонки
        // стали знаковыми: идентификаторы групп в MAX отрицательные.
        DB::table('max_chat_messages')->insert([
            ['max_chat_id' => -777, 'user_id' => -1, 'chat_id' => -777, 'text' => 'группа'],
        ]);

        $this->assertSame(
            [-777],
            DB::table('max_chat_messages')->where('text', 'группа')->pluck('max_chat_id')->all(),
        );
    }

    #[Test]
    public function it_is_a_no_op_on_an_already_repointed_schema(): void
    {
        Schema::create('max_chats', function (Blueprint $table): void {
            $table->bigInteger('chat_id')->primary();
            $table->string('status')->nullable();
        });

        Schema::create('max_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('max_chat_id');
            $table->bigInteger('user_id');
            $table->bigInteger('chat_id');
            $table->string('text')->nullable();
        });

        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->foreign('max_chat_id')->references('chat_id')->on('max_chats')->cascadeOnDelete();
        });

        DB::table('max_chats')->insert(['chat_id' => 555, 'status' => 'active']);
        DB::table('max_chat_messages')->insert([
            ['max_chat_id' => 555, 'user_id' => 10, 'chat_id' => 555, 'text' => 'остаётся'],
        ]);

        $this->repointUp();

        $this->assertSame(
            [555],
            DB::table('max_chat_messages')->pluck('max_chat_id')->all(),
        );

        $this->assertSame(
            [['max_chats', ['chat_id']]],
            $this->foreignKeysOn('max_chat_messages'),
        );
    }

    private function repointUp(): void
    {
        $file = __DIR__ . '/../../database/migrations/0001_01_01_000002_repoint_max_chat_messages_fk.php';

        $migration = require $file;

        $this->assertInstanceOf(Migration::class, $migration);
        $this->assertTrue(is_callable([$migration, 'up']));

        // up() объявлен в анонимном классе миграции, а не в базовом Migration,
        // поэтому вызывается через callable.
        (static function (Migration $m): mixed {
            return Closure::fromCallable([$m, 'up'])->__invoke();
        })($migration);
    }

    /**
     * Схема установки laravel-max-client v1.1.x: у max_chats суррогатный id,
     * а max_chat_messages.max_chat_id — внешний ключ на него.
     */
    private function setUpLegacySchema(): void
    {
        Schema::create('max_chats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->bigInteger('chat_id');
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('max_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('max_chat_id')->constrained('max_chats')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chat_id');
            $table->string('text')->nullable();
            $table->timestamps();
        });
    }
}
