<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Снимает NOT NULL с user_id: у чата, чей состав ещё не в max_chat_users
 * (канал, группа, куда бота только что добавили), собеседника нет.
 *
 * Полоса 0000_02 и вторая позиция в ней обязательны: миграция должна идти после
 * create-миграции 0000_02_000001, иначе change() пришёлся бы на ещё не созданную
 * таблицу. Раскладка полос — в 0000_02_000001_create_max_chat_messages_table.
 *
 * hasTable в up() обязателен: прежнее имя 0001_01_01_000003_make_max_chat_messages_user_id_nullable
 * уже записано в таблицу migrations у установленных приложений. Повторное
 * наложение NULL на уже nullable-колонку безвредно.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('max_chat_messages')) {
            return;
        }

        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->bigInteger('user_id')->nullable()->comment('Идентификатор пользователя MAX; null, если в реестре нет собеседника')->change();
        });
    }

    public function down(): void
    {
        DB::table('max_chat_messages')->whereNull('user_id')->update([
            'user_id' => DB::raw('chat_id'),
        ]);

        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->bigInteger('user_id')->nullable(false)->comment('Идентификатор пользователя MAX')->change();
        });
    }
};
