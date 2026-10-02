<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
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
