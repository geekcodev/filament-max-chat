<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Console;

use GeekCo\FilamentMaxChat\Services\UnreadChatNotifier;
use Illuminate\Console\Command;

/**
 * Шлёт email-дайджест о непрочитанных сообщениях чатов.
 *
 * Команда регистрируется в планировщике хост-приложения автоматически
 * (каждую минуту), пока `email.enabled` включён, — отдельная настройка
 * schedule не нужна. Идемпотентность даёт колонка notified_at: уже
 * уведомлённые строки в выборку не попадают, поэтому повторный запуск
 * в ту же минуту писем не добавляет.
 */
class MaxChatUnreadNotificationCommand extends Command
{
    protected $signature = 'max-chat:notify-unread';

    protected $description = 'Отправить email о непрочитанных сообщениях чатов старше email.unread_after_minutes';

    public function handle(UnreadChatNotifier $notifier): int
    {
        if (config()->boolean('filament-max-chat.email.enabled') === false) {
            $this->components->info('Email-уведомления выключены (filament-max-chat.email.enabled).');

            return self::SUCCESS;
        }

        $sent = $notifier->notify();

        $this->components->info("Отправлено писем: {$sent}");

        return self::SUCCESS;
    }
}
