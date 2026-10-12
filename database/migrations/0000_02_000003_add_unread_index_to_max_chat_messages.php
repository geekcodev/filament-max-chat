<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс под подсчёт непрочитанных: бейдж навигации панели спрашивает
 * totalUnreadCount() — `count(*) where direction = 'in' and read_at is null` — на
 * каждой отрисовке страницы. Существующие индексы эту выборку не покрывают (они
 * ведут с max_chat_id и user_id), поэтому счёт идёт полным сканом
 * max_chat_messages. Когда таблица растёт, скан длится секунды, воркеры Octane
 * заняты, healthcheck /up не успевает и контейнер перезапускается — именно так
 * проявлялся симптом «блоки висят, контейнер unhealthy».
 *
 * Составной индекс (direction, read_at) покрывает и бейдж, и контроллер
 * непрочитанного (latest unread incoming), и markRead работает по существующему
 * индексу с ведущим max_chat_id. Частичный индекс (where read_at is null) был бы
 * компактнее, но он не переносим на MySQL, поэтому берём обычный составной.
 *
 * hasIndex в up() — не перестраховка: приложение-хост могло добавить такой же
 * индекс самостоятельно (проектная миграция), и на существующей установке
 * создание индекса тогда упало бы. Порядок в полосе 0000_02 — после create и
 * nullable-миграций; новую аддитивную миграцию создаёт отдельная версия пакета.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('max_chat_messages')) {
            return;
        }

        if (Schema::hasIndex('max_chat_messages', 'max_chat_messages_unread_index')) {
            return;
        }

        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->index(['direction', 'read_at'], 'max_chat_messages_unread_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('max_chat_messages')) {
            return;
        }

        if (! Schema::hasIndex('max_chat_messages', 'max_chat_messages_unread_index')) {
            return;
        }

        Schema::table('max_chat_messages', function (Blueprint $table): void {
            $table->dropIndex('max_chat_messages_unread_index');
        });
    }
};
