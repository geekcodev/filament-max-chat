<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature;

use GeekCo\FilamentMaxChat\Services\ChatMessagesSchemaRepoint;
use GeekCo\FilamentMaxChat\Tests\Support\InspectsChatSchema;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Вторая фаза перевода max_chat_messages: возврат FK на max_chats.chat_id после
 * того, как адаптер пересобрал реестр чатов.
 *
 * FK нельзя вернуть раньше: на max_chats.chat_id ограничение не создаётся, пока
 * эта колонка не станет первичным ключом, то есть пока не отработает max:upgrade.
 * Поэтому первая фаза оставляет таблицу без ограничения, и его возвращает
 * команда `php artisan max-chat:upgrade`.
 */
class RepinMaxChatMessagesFkTest extends TestCase
{
    use InspectsChatSchema;

    /**
     * RefreshDatabase здесь не нужен и мешает: миграции пакета создают ту же схему
     * с готовым FK, а проверяется вторая фаза — как ограничение возвращается.
     * Схема собирается в каждом тесте с нуля.
     *
     * Отдельная забота: SQLite игнорирует PRAGMA foreign_keys внутри транзакции,
     * а RefreshDatabase открывает её до setUp теста. Без транзакции переключатель
     * работает, и проверки отказа для неизвестного чата и каскада получаются
     * настоящими.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('max_chat_messages');
        Schema::dropIfExists('max_chat_users');
        Schema::dropIfExists('max_chats');

        Schema::enableForeignKeyConstraints();
    }

    #[Test]
    public function it_adds_the_foreign_key_to_the_rebuilt_registry(): void
    {
        $this->setUpRebuiltSchema();

        $repoint = app(ChatMessagesSchemaRepoint::class);

        $this->assertTrue($repoint->needsRepin());

        $repoint->repin();

        $this->assertFalse($repoint->needsRepin());
        $this->assertSame(
            [['max_chats', ['chat_id']]],
            $this->foreignKeysOn('max_chat_messages'),
        );
    }

    #[Test]
    public function it_rejects_a_message_from_an_unknown_chat(): void
    {
        $this->setUpRebuiltSchema();

        app(ChatMessagesSchemaRepoint::class)->repin();

        DB::table('max_chats')->insert(['chat_id' => 555, 'status' => 'active']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('max_chat_messages')->insert([
            'max_chat_id' => 4242,
            'user_id' => 10,
            'chat_id' => 4242,
            'direction' => 'incoming',
            'sender_type' => 'user',
            'text' => 'нет такого чата',
        ]);
    }

    #[Test]
    public function it_accepts_a_negative_chat_id_of_a_group(): void
    {
        $this->setUpRebuiltSchema();

        app(ChatMessagesSchemaRepoint::class)->repin();

        DB::table('max_chats')->insert(['chat_id' => -777, 'status' => 'active']);
        DB::table('max_chat_messages')->insert([
            'max_chat_id' => -777,
            'user_id' => -1,
            'chat_id' => -777,
            'direction' => 'incoming',
            'sender_type' => 'user',
            'text' => 'сообщение группы',
        ]);

        $this->assertSame(
            [-777],
            DB::table('max_chat_messages')->pluck('max_chat_id')->all(),
        );
    }

    #[Test]
    public function it_deletes_messages_with_the_chat(): void
    {
        $this->setUpRebuiltSchema();

        app(ChatMessagesSchemaRepoint::class)->repin();

        DB::table('max_chats')->insert(['chat_id' => 555, 'status' => 'active']);
        DB::table('max_chat_messages')->insert([
            'max_chat_id' => 555,
            'user_id' => 10,
            'chat_id' => 555,
            'direction' => 'incoming',
            'sender_type' => 'user',
            'text' => 'до удаления чата',
        ]);

        DB::table('max_chats')->where('chat_id', 555)->delete();

        $this->assertSame(0, DB::table('max_chat_messages')->count());
    }

    #[Test]
    public function it_is_a_no_op_when_the_foreign_key_is_already_there(): void
    {
        $this->setUpRebuiltSchema(withMessages: false);
        Schema::create('max_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('max_chat_id');
            $table->bigInteger('user_id');
            $table->bigInteger('chat_id');
            $table->string('direction')->nullable();
            $table->string('sender_type')->nullable();
            $table->string('text')->nullable();
            $table->foreign('max_chat_id')->references('chat_id')->on('max_chats')->cascadeOnDelete();
        });

        $repoint = app(ChatMessagesSchemaRepoint::class);

        $this->assertFalse($repoint->needsRepin());

        $repoint->repin();

        $this->assertSame(
            [['max_chats', ['chat_id']]],
            $this->foreignKeysOn('max_chat_messages'),
        );
    }

    #[Test]
    public function it_needs_nothing_when_the_registry_is_still_legacy(): void
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

        $repoint = app(ChatMessagesSchemaRepoint::class);

        $this->assertTrue($repoint->needsRemap());
        $this->assertFalse($repoint->needsRepin());
    }

    /**
     * Форма реестра после max:upgrade: первичный ключ chat_id, колонок id и
     * user_id нет.
     */
    private function setUpRebuiltSchema(bool $withMessages = true): void
    {
        Schema::create('max_chats', function (Blueprint $table): void {
            $table->bigInteger('chat_id')->primary();
            $table->string('status')->nullable();
            $table->string('chat_type')->nullable();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        if ($withMessages) {
            Schema::create('max_chat_messages', function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('max_chat_id');
                $table->bigInteger('user_id');
                $table->bigInteger('chat_id');
                $table->string('direction')->nullable();
                $table->string('sender_type')->nullable();
                $table->string('text')->nullable();
            });
        }
    }

}
