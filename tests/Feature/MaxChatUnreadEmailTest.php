<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Mail\UnreadChatsMail;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Services\UnreadChatNotifier;
use GeekCo\FilamentMaxChat\Tests\Fixtures\TestUser;
use GeekCo\FilamentMaxChat\Tests\Support\MakesChats;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Email-уведомления о непрочитанных чатах (max-chat:notify-unread):
 * порог unread_after_minutes, однократная отправка через notified_at,
 * получатели «email.to + последний ответивший в чате».
 *
 * Очередь в TestCase подменена (Queue::fake), поэтому проверяется именно
 * синхронная отправка Mail::to()->send(), а не отложенная задача.
 */
class MaxChatUnreadEmailTest extends TestCase
{
    use MakesChats;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_disabled_by_default_runs_without_sending(): void
    {
        $chat = $this->chat(101);
        $message = $this->incoming($chat, 30);
        $this->reply($chat, $this->operator('op1@example.test'));

        [$exit, $output] = $this->runCommand();

        $this->assertSame(0, $exit);
        self::assertStringContainsString('выключены', $output);
        Mail::assertNothingSent();
        $this->assertNull($message->fresh()?->notified_at);
    }

    public function test_notifier_returns_zero_when_disabled(): void
    {
        $this->assertSame(0, app(UnreadChatNotifier::class)->notify());
    }

    public function test_old_unread_message_notifies_last_responder_once(): void
    {
        config()->set('filament-max-chat.email.enabled', true);

        $operator = $this->operator('responder@example.test');
        $chat = $this->chat(102);
        $message = $this->incoming($chat, 10);
        $this->reply($chat, $operator);

        [$exit] = $this->runCommand();

        $this->assertSame(0, $exit);
        Mail::assertSent(
            UnreadChatsMail::class,
            static fn (UnreadChatsMail $mail): bool => $mail->hasTo('responder@example.test')
                && count($mail->chats) === 1,
        );
        $this->assertNotNull($message->fresh()?->notified_at);

        $this->runCommand();

        Mail::assertSentTimes(UnreadChatsMail::class, 1);
    }

    public function test_fresh_unread_message_is_not_notified(): void
    {
        config()->set('filament-max-chat.email.enabled', true);

        $chat = $this->chat(103);
        $message = $this->incoming($chat, 2);
        $this->reply($chat, $this->operator('op3@example.test'));

        [, $output] = $this->runCommand();

        self::assertStringContainsString('Отправлено писем: 0', $output);
        Mail::assertNothingSent();
        $this->assertNull($message->fresh()?->notified_at);
    }

    public function test_read_message_is_not_notified(): void
    {
        config()->set('filament-max-chat.email.enabled', true);

        $chat = $this->chat(104);
        $message = $this->incoming($chat, 30);
        $message->forceFill(['read_at' => now()])->save();
        $this->reply($chat, $this->operator('op4@example.test'));

        $this->runCommand();

        Mail::assertNothingSent();
        $this->assertNull($message->fresh()?->notified_at);
    }

    public function test_configured_recipients_get_digest_without_responder(): void
    {
        config()->set('filament-max-chat.email.enabled', true);
        config()->set('filament-max-chat.email.to', ['lead@example.test', 'deputy@example.test']);

        $chat = $this->chat(105);
        $message = $this->incoming($chat, 10);

        $this->runCommand();

        Mail::assertSentTimes(UnreadChatsMail::class, 2);
        Mail::assertSent(
            UnreadChatsMail::class,
            static fn (UnreadChatsMail $mail): bool => $mail->hasTo('lead@example.test'),
        );
        Mail::assertSent(
            UnreadChatsMail::class,
            static fn (UnreadChatsMail $mail): bool => $mail->hasTo('deputy@example.test'),
        );
        $this->assertNotNull($message->fresh()?->notified_at);
    }

    public function test_invalid_configured_recipient_is_filtered(): void
    {
        config()->set('filament-max-chat.email.enabled', true);
        config()->set('filament-max-chat.email.to', ['not-an-email']);

        $chat = $this->chat(106);
        $message = $this->incoming($chat, 10);

        $this->runCommand();

        Mail::assertNothingSent();
        $this->assertNull($message->fresh()?->notified_at);
    }

    public function test_pending_message_is_sent_when_recipient_appears_later(): void
    {
        config()->set('filament-max-chat.email.enabled', true);

        $chat = $this->chat(107);
        $message = $this->incoming($chat, 10);

        $this->runCommand();

        Mail::assertNothingSent();
        $this->assertNull($message->fresh()?->notified_at);

        $this->reply($chat, $this->operator('late@example.test'));

        $this->runCommand();

        Mail::assertSent(
            UnreadChatsMail::class,
            static fn (UnreadChatsMail $mail): bool => $mail->hasTo('late@example.test'),
        );
        $this->assertNotNull($message->fresh()?->notified_at);
    }

    public function test_responder_gets_own_chat_shared_list_gets_all(): void
    {
        config()->set('filament-max-chat.email.enabled', true);
        config()->set('filament-max-chat.email.to', ['lead@example.test']);

        $chatA = $this->chat(108);
        $chatB = $this->chat(109);
        $this->incoming($chatA, 10);
        $this->reply($chatA, $this->operator('a@example.test'));
        $this->incoming($chatB, 20);
        $this->reply($chatB, $this->operator('b@example.test'));

        $this->runCommand();

        Mail::assertSentTimes(UnreadChatsMail::class, 3);
        Mail::assertSent(UnreadChatsMail::class, static function (UnreadChatsMail $mail): bool {
            return $mail->hasTo('lead@example.test')
                && array_column($mail->chats, 'chat_id') === [108, 109];
        });
        Mail::assertSent(UnreadChatsMail::class, static function (UnreadChatsMail $mail): bool {
            return $mail->hasTo('a@example.test')
                && array_column($mail->chats, 'chat_id') === [108];
        });
        Mail::assertSent(UnreadChatsMail::class, static function (UnreadChatsMail $mail): bool {
            return $mail->hasTo('b@example.test')
                && array_column($mail->chats, 'chat_id') === [109];
        });
    }

    public function test_unread_after_minutes_threshold_and_grouping(): void
    {
        config()->set('filament-max-chat.email.enabled', true);
        config()->set('filament-max-chat.email.unread_after_minutes', 30);
        config()->set('filament-max-chat.email.to', ['lead@example.test']);

        $chat = $this->chat(110);
        $fresh = $this->incoming($chat, 10);
        $old = $this->incoming($chat, 40);
        $oldest = $this->incoming($chat, 60);

        $this->runCommand();

        $mailable = Mail::sent(UnreadChatsMail::class)->first();
        $this->assertInstanceOf(UnreadChatsMail::class, $mailable);
        $this->assertCount(1, $mailable->chats);
        $this->assertSame(2, $mailable->chats[0]['count']);

        $this->assertNull($fresh->fresh()?->notified_at);
        $this->assertNotNull($old->fresh()?->notified_at);
        $this->assertNotNull($oldest->fresh()?->notified_at);
    }

    public function test_mail_renders_chat_name_preview_and_link(): void
    {
        config()->set('filament-max-chat.email.enabled', true);
        config()->set('filament-max-chat.email.to', ['lead@example.test']);

        $chat = $this->chat(111);
        $this->incoming($chat, 10, ['text' => 'Как дела с доставкой?']);

        $this->runCommand();

        $mailable = Mail::sent(UnreadChatsMail::class)->first();
        $this->assertInstanceOf(UnreadChatsMail::class, $mailable);

        $html = $mailable->render();
        self::assertStringContainsString('Пользователь', $html);
        self::assertStringContainsString('Как дела с доставкой?', $html);
        self::assertStringContainsString('chat_id=111', $html);

        // Имя вьюхи — в переменной с @var view-string: литерал phpstan не
        // принимает (gotcha 20), как и в src.
        /** @var view-string $textView */
        $textView = 'filament-max-chat::mail.unread-chats-text';
        $text = view($textView, ['chats' => $mailable->chats])->render();
        self::assertStringContainsString('chat_id=111', $text);
        self::assertStringContainsString('Как дела с доставкой?', $text);
    }

    public function test_attachment_only_message_shows_type_label(): void
    {
        config()->set('filament-max-chat.email.enabled', true);
        config()->set('filament-max-chat.email.to', ['lead@example.test']);

        $chat = $this->chat(112);
        $this->incoming($chat, 10, [
            'text' => null,
            'attachment' => [['type' => 'file', 'path' => 'a/b.pdf', 'name' => 'b.pdf']],
        ]);

        $this->runCommand();

        $mailable = Mail::sent(UnreadChatsMail::class)->first();
        $this->assertInstanceOf(UnreadChatsMail::class, $mailable);

        $html = $mailable->render();
        self::assertStringContainsString(__('filament-max-chat::chat.preview.file'), $html);
    }

    public function test_send_failure_keeps_messages_unnotified(): void
    {
        config()->set('filament-max-chat.email.enabled', true);
        config()->set('filament-max-chat.email.to', ['lead@example.test']);

        $chat = $this->chat(113);
        $message = $this->incoming($chat, 10);

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));

        [$exit] = $this->runCommand();

        $this->assertSame(0, $exit);
        $this->assertNull($message->fresh()?->notified_at);
    }

    public function test_chat_missing_from_registry_is_marked_without_mail(): void
    {
        config()->set('filament-max-chat.email.enabled', true);
        config()->set('filament-max-chat.email.to', ['lead@example.test']);

        // Чата нет в max_chats: в тестах внешние ключи SQLite выключены,
        // что и воспроизводит установку во время переезда реестра.
        $message = MaxMessage::query()->create([
            'max_chat_id' => 9999,
            'user_id' => null,
            'chat_id' => 9999,
            'direction' => MaxMessageDirection::In,
            'sender_type' => MaxMessageSender::User,
            'text' => 'Сообщение в чат без реестра',
        ]);
        MaxMessage::query()
            ->whereKey($message->getKey())
            ->update(['created_at' => now()->subMinutes(10)]);

        [, $output] = $this->runCommand();

        self::assertStringContainsString('Отправлено писем: 0', $output);
        Mail::assertNothingSent();
        $this->assertNotNull($message->fresh()?->notified_at);
    }

    private function operator(string $email): TestUser
    {
        return TestUser::query()->create([
            'name' => 'Оператор',
            'email' => $email,
            'password' => 'password',
            'can_view_chat' => true,
            'can_answer_chat' => true,
        ]);
    }

    private function chat(int $chatId): MaxChat
    {
        return $this->makeChatWithUser($chatId, $chatId + 1000, ['first_name' => 'Пользователь']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function incoming(MaxChat $chat, int $minutesAgo, array $extra = []): MaxMessage
    {
        $message = MaxMessage::query()->create(array_merge([
            'max_chat_id' => $chat->chat_id,
            'user_id' => $chat->interlocutorId(),
            'chat_id' => $chat->chat_id,
            'direction' => MaxMessageDirection::In,
            'sender_type' => MaxMessageSender::User,
            'text' => 'Подскажите статус заказа',
        ], $extra));

        MaxMessage::query()
            ->whereKey($message->getKey())
            ->update(['created_at' => now()->subMinutes($minutesAgo)]);

        return $message;
    }

    private function reply(MaxChat $chat, TestUser $operator): MaxMessage
    {
        return MaxMessage::query()->create([
            'max_chat_id' => $chat->chat_id,
            'user_id' => $chat->interlocutorId(),
            'chat_id' => $chat->chat_id,
            'direction' => MaxMessageDirection::Out,
            'sender_type' => MaxMessageSender::Operator,
            'operator_id' => $operator->getKey(),
            'text' => 'Здравствуйте! Уточняем.',
        ]);
    }

    /**
     * @return array{int, string}
     */
    private function runCommand(): array
    {
        $kernel = app(Kernel::class);
        $output = new BufferedOutput();

        return [$kernel->call('max-chat:notify-unread', [], $output), $output->fetch()];
    }
}
