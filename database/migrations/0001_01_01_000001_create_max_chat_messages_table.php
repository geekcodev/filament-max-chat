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

            // Локальный FK на реестр чатов. С v1.2.0 laravel-max-client строка
            // реестра одна на чат, первичный ключ в max_chats — сам chat_id, а
            // суррогатного id больше нет: ссылаемся на chat_id и держим его
            // знаковым (у групп и каналов идентификатор отрицательный).
            // Не путать с системными user_id/chat_id ниже: user_id и chat_id —
            // идентификаторы пользователя и чата внутри экосистемы MAX, они
            // хранятся в каждом сообщении для истории и поиска.
            $table->bigInteger('max_chat_id');
            $table->bigInteger('user_id')->comment('Идентификатор пользователя MAX');
            $table->bigInteger('chat_id')->comment('Идентификатор чата в MAX');
            $table->string('message_id')->nullable()->comment('Идентификатор сообщения в MAX');
            $table->string('direction', 8)->comment('Направление: in/out');
            $table->string('sender_type', 16)->comment('Отправитель: operator/user');
            $table->text('text')->nullable()->comment('Текст сообщения');
            $table->json('attachment')->nullable()->comment('Метаданные вложения (type/path/name/mime/size)');
            $table->foreignId('operator_id')->nullable()->comment('Оператор, отправивший ответ')->constrained('users')->nullOnDelete();
            $table->timestamp('read_at')->nullable()->comment('Время прочтения оператором');
            $table->timestamps();

            $table->foreign('max_chat_id')->references('chat_id')->on('max_chats')->cascadeOnDelete();
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
