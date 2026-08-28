<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('max_chat_messages', function (Blueprint $table): void {
            $table->id();

            // Локальный FK на реестр чатов (max_chats.id). Не путать с системными
            // user_id/chat_id ниже: max_chat_id — это запись локального реестра диалогов
            // (включая оператора-бота), а user_id и chat_id — идентификаторы пользователя
            // и чата внутри экосистемы MAX.
            $table->foreignId('max_chat_id')->constrained('max_chats')->cascadeOnDelete();

            $table->unsignedBigInteger('user_id')->comment('Идентификатор пользователя MAX');
            $table->unsignedBigInteger('chat_id')->comment('Идентификатор чата в MAX');
            $table->string('message_id')->nullable()->comment('Идентификатор сообщения в MAX');
            $table->string('direction', 8)->comment('Направление: in/out');
            $table->string('sender_type', 16)->comment('Отправитель: operator/user');
            $table->text('text')->nullable()->comment('Текст сообщения');
            $table->json('attachment')->nullable()->comment('Метаданные вложения (type/path/name/mime/size)');
            $table->foreignId('operator_id')->nullable()->comment('Оператор, отправивший ответ')->constrained('users')->nullOnDelete();
            $table->timestamp('read_at')->nullable()->comment('Время прочтения оператором');
            $table->timestamps();

            $table->index(['max_chat_id', 'created_at']);
            $table->index(['user_id', 'chat_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('max_chat_messages');
    }
};
