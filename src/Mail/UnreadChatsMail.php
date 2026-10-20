<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Дайджест о непрочитанных чатах: по одной строке на чат с числом
 * непрочитанных сообщений, превью последнего и ссылкой на открытие диалога.
 * Отправляется синхронно из max-chat:notify-unread, без очереди.
 */
class UnreadChatsMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param list<array{chat_id: int, name: string, count: int, preview: string, url: string}> $chats
     */
    public function __construct(
        public array $chats,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('filament-max-chat::chat.mail.subject'),
        );
    }

    public function content(): Content
    {
        // Имена вьюх — в переменных с @var view-string: литерал в phpstan-типы
        // не попадает (gotcha 20, дрейф Larastan по view-string).
        /** @var view-string $html */
        $html = 'filament-max-chat::mail.unread-chats';
        /** @var view-string $text */
        $text = 'filament-max-chat::mail.unread-chats-text';

        return new Content(
            html: $html,
            text: $text,
            with: ['chats' => $this->chats],
        );
    }
}
