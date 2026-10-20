<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Колонка notified_at фиксирует, что email-уведомление о непрочитанном
 * сообщении уже ушло операторам. Команда max-chat:notify-unread выбирает
 * входящие строки с read_at is null и notified_at is null старше порога
 * email.unread_after_minutes, шлёт дайджест и помечает отправленное —
 * напоминание однократное, повторов по одному сообщению нет.
 *
 * Индекс не создаётся: выборку ведёт составной (direction, read_at) из
 * 0000_02_000003, а notified_at фильтруется уже внутри небольшого множества
 * непрочитанных строк.
 *
 * hasTable и hasColumn — приложение-хост могло завести такую колонку само,
 * и на существующей установке повторное добавление тогда упало бы.
 * Полоса 0000_02 — после create и аддитивных миграций пакета.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('max_chat_messages')) {
            return;
        }

        if (Schema::hasColumn('max_chat_messages', 'notified_at')) {
            return;
        }

        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->timestamp('notified_at')->nullable()->comment('Время email-уведомления о непрочитанном; null — ещё не уведомлялось');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('max_chat_messages')) {
            return;
        }

        if (! Schema::hasColumn('max_chat_messages', 'notified_at')) {
            return;
        }

        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->dropColumn('notified_at');
        });
    }
};
