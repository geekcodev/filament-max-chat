<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Mail\UnreadChatsMail;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Pages\OperatorChat as OperatorChatPage;
use GeekCo\LaravelMaxClient\Support\Logger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Email-дайджест о непрочитанных сообщениях чатов.
 *
 * Условие отправки: входящее сообщение не прочитано (`read_at` null) дольше
 * `email.unread_after_minutes` и ещё не уведомлялось (`notified_at` null).
 * Получатели — общий список `email.to` (после проверки filter_var) плюс
 * оператор, последний отвечавший в чате: его email берётся из `user_model`
 * по `operator_id` последнего исходящего сообщения, потому что в реестре
 * адаптера привязки «чат → оператор» нет.
 *
 * Напоминание однократное: после успешной доставки хотя бы одному получателю
 * сообщения помечаются `notified_at`, повторов по ним нет. Чат без единого
 * получателя не помечается — попытка повторится при следующем запуске, как
 * только адресат появится (например, в `email.to`).
 */
class UnreadChatNotifier
{
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    /**
     * Отправляет дайджесты по чатам и возвращает число отправленных писем.
     */
    public function notify(): int
    {
        if (config()->boolean('filament-max-chat.email.enabled') === false) {
            return 0;
        }

        $pending = $this->pendingByChat();

        if ($pending === []) {
            return 0;
        }

        /** @var array<string, list<array{chat_id: int, name: string, count: int, preview: string, url: string}>> $digests */
        $digests = [];
        /** @var array<int, bool> $delivered */
        $delivered = [];

        foreach ($pending as $chatId => $messages) {
            $chat = MaxChat::query()->find($chatId);

            if (! $chat instanceof MaxChat) {
                // Чат выпал из реестра: показывать в письме нечего, но и
                // заново сканировать его строки при каждом минутном запуске
                // нельзя — помечаем как уведомлённые без отправки.
                $this->logger->log('info', 'Unread chat email skipped: chat not in registry.', [
                    'chat_id' => $chatId,
                ]);
                $this->markNotified($this->idsOf($messages));

                continue;
            }

            $recipients = $this->recipientsFor($chatId);

            if ($recipients === []) {
                continue;
            }

            $entry = [
                'chat_id' => $chatId,
                'name' => $chat->displayName(),
                'count' => $messages->count(),
                'preview' => $this->previewFor($messages),
                'url' => $this->urlFor($chatId),
            ];

            foreach ($recipients as $email) {
                $digests[$email][] = $entry;
            }

            $delivered[$chatId] = $delivered[$chatId] ?? false;
        }

        $sent = 0;

        foreach ($digests as $email => $chats) {
            try {
                Mail::to($email)->send(new UnreadChatsMail($chats));
                $sent++;

                foreach ($chats as $entry) {
                    $delivered[$entry['chat_id']] = true;
                }
            } catch (Throwable $exception) {
                $this->logger->log('error', 'Unread chat email notification failed.', [
                    'recipient' => $email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        foreach ($delivered as $chatId => $isDelivered) {
            if ($isDelivered) {
                $this->markNotified($this->idsOf($pending[$chatId]));
            }
        }

        return $sent;
    }

    /**
     * Непрочитанные входящие старше порога и ещё не уведомлённые, сгруппированные
     * по чату; порядок чатов и сообщений — по возрастанию id.
     *
     * @return array<int, Collection<int, MaxMessage>>
     */
    private function pendingByChat(): array
    {
        $delay = max(0, config()->integer('filament-max-chat.email.unread_after_minutes'));
        $cutoff = now()->subMinutes($delay);

        /** @var array<int, Collection<int, MaxMessage>> $pending */
        $pending = [];

        MaxMessage::query()
            ->where('direction', MaxMessageDirection::In)
            ->whereNull('read_at')
            ->whereNull('notified_at')
            ->where('created_at', '<=', $cutoff)
            ->lazyById(500, 'id')
            ->each(function (MaxMessage $message) use (&$pending): void {
                $chatId = $message->max_chat_id;

                $pending[$chatId] ??= new Collection();
                $pending[$chatId]->push($message);
            });

        ksort($pending);

        return $pending;
    }

    /**
     * Получатели одного чата: общий список email.to и последний ответивший.
     *
     * @return list<string>
     */
    private function recipientsFor(int $chatId): array
    {
        $recipients = $this->configuredRecipients();

        $responder = $this->lastResponderEmail($chatId);

        if ($responder !== null) {
            $recipients[] = $responder;
        }

        return array_values(array_unique($recipients));
    }

    /**
     * @return list<string>
     */
    private function configuredRecipients(): array
    {
        $configured = config()->array('filament-max-chat.email.to', []);

        $emails = [];

        foreach ($configured as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $value;
            }
        }

        return $emails;
    }

    /**
     * Email оператора, последнего отвечавшего в чате: у исходящих сообщений
     * хранится `operator_id`, у входящих его нет, поэтому ответственного можно
     * взять только из истории ответов.
     */
    private function lastResponderEmail(int $chatId): ?string
    {
        $operatorId = MaxMessage::query()
            ->where('max_chat_id', $chatId)
            ->where('direction', MaxMessageDirection::Out)
            ->whereNotNull('operator_id')
            ->orderByDesc('id')
            ->value('operator_id');

        if ($operatorId === null || ! is_numeric($operatorId)) {
            return null;
        }

        /** @var class-string<Model> $userModel */
        $userModel = config()->string('filament-max-chat.user_model');

        $email = $userModel::query()->find((int) $operatorId)?->getAttribute('email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email
            : null;
    }

    /**
     * Превью для письма: имя отправителя и сжатый текст последнего сообщения
     * чата, у вложения — типовая подпись из previewText().
     *
     * @param Collection<int, MaxMessage> $messages
     */
    private function previewFor(Collection $messages): string
    {
        $last = $messages->last();

        if (! $last instanceof MaxMessage) {
            return '';
        }

        $text = Str::limit($last->previewText(), 160);
        $sender = $last->senderName();

        return $sender !== null && $sender !== ''
            ? $sender.': '.$text
            : $text;
    }

    /**
     * Ссылка на страницу чата: mount() страницы читает `chat_id` из query string
     * и открывает диалог.
     */
    private function urlFor(int $chatId): string
    {
        return OperatorChatPage::getUrl(['chat_id' => $chatId]);
    }

    /**
     * @param Collection<int, MaxMessage> $messages
     * @return list<int>
     */
    private function idsOf(Collection $messages): array
    {
        return array_values($messages
            ->map(static fn (MaxMessage $message): int => $message->id)
            ->all());
    }

    /**
     * @param list<int> $ids
     */
    private function markNotified(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            MaxMessage::query()
                ->whereIn('id', $chunk)
                ->update(['notified_at' => now()]);
        }
    }
}
