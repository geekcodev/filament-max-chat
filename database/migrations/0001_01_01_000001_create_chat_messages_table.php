<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bot_chat_id')->constrained('max_bot_chats')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chat_id');
            $table->string('message_id')->nullable();
            $table->string('direction', 8);
            $table->string('sender_type', 16);
            $table->text('text')->nullable();
            $table->json('attachment')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['bot_chat_id', 'created_at']);
            $table->index(['user_id', 'chat_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
