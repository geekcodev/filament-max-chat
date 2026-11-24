<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Метаданные связанного сообщения: ответ (`reply`) или пересылка (`forward`).
 * MAX присылает их в `message.link`, но история должна показывать цитату и
 * после перезагрузки ленты, поэтому данные сохраняются в JSON-колонке.
 *
 * Миграция аддитивная и идемпотентная: колонка nullable, существующие строки
 * остаются без изменений.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('max_chat_messages') || Schema::hasColumn('max_chat_messages', 'link')) {
            return;
        }

        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->json('link')->nullable()->after('attachment')->comment('Связанное сообщение: reply/forward');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('max_chat_messages') || ! Schema::hasColumn('max_chat_messages', 'link')) {
            return;
        }

        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->dropColumn('link');
        });
    }
};
