<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature;

use GeekCo\FilamentMaxChat\Tests\Support\InspectsChatSchema;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Services\MaxSchemaUpgrade;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use PHPUnit\Framework\Attributes\Test;

/**
 * Команда `php artisan max-chat:upgrade` прогоняет обе фазы перевода и зовёт
 * `max:upgrade` адаптера между ними: перенос значений обязан случиться до
 * пересборки реестра, а возврат FK — после.
 *
 * Здесь проверяется порядок и итоговое состояние на настоящей связке: команда
 * адаптера пересобирает max_chats по-настоящему.
 */
class MaxChatUpgradeCommandTest extends TestCase
{
    use InspectsChatSchema;

    /**
     * Старая форма берётся не руками, а откатом самого адаптера: он определяет
     * прежнюю форму max_chats, и собранная вручную таблица рано или поздно
     * разошлась бы с настоящей.
     *
     * RefreshDatabase здесь не нужен и мешает: миграции прогоняются явно, а
     * транзакция, которую открывает трейт, делает бесполезным включение внешних
     * ключей в SQLite — проверки отказа для неизвестного чата и каскада должны
     * быть настоящими.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('max_chat_messages');
        Schema::dropIfExists('max_chat_users');
        Schema::dropIfExists('max_chats');
        Schema::dropIfExists('max_users');

        // migrate:fresh, а не migrate: пути миграций адаптера TestCase
        // регистрирует только вместе с RefreshDatabase, а он здесь не нужен.
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => 'testing']));

        // FK истории переписки мешает откату пересобрать max_chats, а в старой
        // форме реестра он и должен упираться в суррогатный id.
        Schema::dropIfExists('max_chat_messages');

        app(MaxSchemaUpgrade::class)->rollback();

        Schema::enableForeignKeyConstraints();
    }

    #[Test]
    public function it_translates_a_legacy_registry_in_one_run(): void
    {
        $this->setUpLegacySchema();

        DB::table('max_users')->insert($this->maxUser(10, 'Клиент'));
        DB::table('max_users')->insert($this->maxUser(11, 'Другой клиент'));
        DB::table('max_chats')->insert([
            ['id' => 1, 'user_id' => 10, 'chat_id' => 555, 'status' => 'active'],
            ['id' => 2, 'user_id' => 11, 'chat_id' => 555, 'status' => 'active'],
            ['id' => 3, 'user_id' => 10, 'chat_id' => -777, 'status' => 'active'],
        ]);
        DB::table('max_chat_messages')->insert([
            ['max_chat_id' => 1, 'user_id' => 10, 'chat_id' => 555, 'text' => 'диалог'],
            ['max_chat_id' => 2, 'user_id' => 11, 'chat_id' => 555, 'text' => 'второй участник того же чата'],
            ['max_chat_id' => 3, 'user_id' => 10, 'chat_id' => -777, 'text' => 'группа'],
        ]);

        $this->assertSame(0, Artisan::call('max-chat:upgrade'));

        // Реестр пересобран адаптером: колонок id и user_id больше нет.
        $this->assertFalse(Schema::hasColumn('max_chats', 'id'));
        $this->assertFalse(Schema::hasColumn('max_chats', 'user_id'));
        $this->assertSame([[-777, 'active'], [555, 'active']], $this->registry());

        // Пары «чат + пользователь» разошлись в max_chat_users.
        $this->assertSame([[-777, 10], [555, 10], [555, 11]], $this->chatUserPairs());

        // Значения истории переписки перенесены на chat_id.
        $this->assertSame(
            [555, 555, -777],
            DB::table('max_chat_messages')->orderBy('id')->pluck('max_chat_id')->all(),
        );

        // FK вернулся и уже на chat_id.
        $this->assertSame(
            [['max_chats', ['chat_id']]],
            $this->foreignKeysOn('max_chat_messages'),
        );
    }

    #[Test]
    public function it_keeps_the_registry_when_a_chat_is_deleted(): void
    {
        $this->setUpLegacySchema();

        DB::table('max_users')->insert($this->maxUser(10, 'Клиент'));
        DB::table('max_chats')->insert(['id' => 1, 'user_id' => 10, 'chat_id' => 555, 'status' => 'active']);
        DB::table('max_chat_messages')->insert([
            ['max_chat_id' => 1, 'user_id' => 10, 'chat_id' => 555, 'text' => 'до удаления чата'],
        ]);

        $this->assertSame(0, Artisan::call('max-chat:upgrade'));

        DB::table('max_chats')->where('chat_id', 555)->delete();

        $this->assertSame(0, DB::table('max_chat_messages')->count());
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $this->setUpLegacySchema();

        DB::table('max_users')->insert($this->maxUser(10, 'Клиент'));
        DB::table('max_chats')->insert(['id' => 1, 'user_id' => 10, 'chat_id' => 555, 'status' => 'active']);
        DB::table('max_chat_messages')->insert([
            ['max_chat_id' => 1, 'user_id' => 10, 'chat_id' => 555, 'text' => 'привет'],
        ]);

        $this->assertSame(0, Artisan::call('max-chat:upgrade'));
        $this->assertSame(0, Artisan::call('max-chat:upgrade'));

        $this->assertSame([[555, 'active']], $this->registry());
        $this->assertSame([555], DB::table('max_chat_messages')->pluck('max_chat_id')->all());
        $this->assertSame([['max_chats', ['chat_id']]], $this->foreignKeysOn('max_chat_messages'));
    }

    #[Test]
    public function it_refuses_to_run_without_the_messages_table(): void
    {
        Schema::dropIfExists('max_chat_messages');

        $this->assertSame(1, Artisan::call('max-chat:upgrade'));
        $this->assertStringContainsString('Сначала выполните `php artisan migrate`', Artisan::output());
    }

    #[Test]
    public function it_refuses_to_run_without_the_chat_users_table(): void
    {
        $this->setUpLegacySchema();
        Schema::drop('max_chat_users');

        $this->assertSame(1, Artisan::call('max-chat:upgrade'));
        $this->assertStringContainsString('Нет таблицы max_chat_users', Artisan::output());
    }

    #[Test]
    public function it_hints_to_repeat_the_command_when_the_adapter_fails(): void
    {
        $this->setUpLegacySchema();

        DB::table('max_users')->insert($this->maxUser(10, 'Клиент'));
        DB::table('max_chats')->insert(['id' => 1, 'user_id' => 10, 'chat_id' => 555, 'status' => 'active']);
        DB::table('max_chat_messages')->insert([
            ['max_chat_id' => 1, 'user_id' => 10, 'chat_id' => 555, 'text' => 'привет'],
        ]);

        // Падение пересборки устраивается настоящей ошибкой адаптера: без таблицы
        // max_users он не может расширить колонку идентификатора. Мок фасада
        // Artisan здесь не годится — ядро Testbench помечает свой Kernel как
        // final.
        Schema::drop('max_users');

        // Вывод читается из собственного буфера: вложенный вызов max:upgrade
        // перетирает последний буфер Artisan, поэтому Artisan::output() пуст.
        $output = new BufferedOutput();
        $exitCode = app(Kernel::class)->call('max-chat:upgrade', [], $output);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Повторный запуск безопасен', $output->fetch());

        // Значения уже перенесены, ограничение ещё не вернулось — состояние
        // возобновляемое, повторный запуск перевод завершит.
        $this->assertSame([555], DB::table('max_chat_messages')->pluck('max_chat_id')->all());
        $this->assertSame([], $this->foreignKeysOn('max_chat_messages'));
    }

    /**
     * Реестр чатов уже в старой форме (её вернул откат адаптера в setUp).
     * Добавляется только история переписки в прежней форме: беззнаковые колонки
     * идентификаторов и FK на суррогатный id.
     */
    private function setUpLegacySchema(): void
    {
        Schema::create('max_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('max_chat_id')->constrained('max_chats')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chat_id');
            $table->string('direction')->nullable();
            $table->string('sender_type')->nullable();
            $table->string('text')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Реестр чатов как [[chat_id, status], ...] в порядке chat_id.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function registry(): array
    {
        $rows = DB::table('max_chats')->orderBy('chat_id')->select('chat_id', 'status')->get();

        $registry = [];

        foreach ($rows as $row) {
            $this->assertIsInt($row->chat_id);
            $this->assertIsString($row->status);

            $registry[] = [$row->chat_id, $row->status];
        }

        return $registry;
    }

    /**
     * Связи чатов и пользователей как [[chat_id, user_id], ...].
     *
     * @return list<array{0: int, 1: int}>
     */
    private function chatUserPairs(): array
    {
        $rows = DB::table('max_chat_users')->orderBy('chat_id')->orderBy('user_id')
            ->select('chat_id', 'user_id')->get();

        $pairs = [];

        foreach ($rows as $row) {
            $this->assertIsInt($row->chat_id);
            $this->assertIsInt($row->user_id);

            $pairs[] = [$row->chat_id, $row->user_id];
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>
     */
    private function maxUser(int $id, string $name): array
    {
        return [
            'user_id' => $id,
            'first_name' => $name,
            'name' => $name,
            'is_bot' => false,
        ];
    }

}
