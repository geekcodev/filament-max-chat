<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Listeners;

use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Enum\UpdateType;

/**
 * Автообработка апдейтов MAX для истории переписки плагина. Включается ключом
 * `filament-max-chat.updates.auto_handle` (по умолчанию включена) и регистрируется
 * провайдером на событие `MaxUpdateReceived` пакета laravel-max-client.
 *
 * Обрабатывает только то, что касается истории чата: создание, правку и удаление
 * сообщения, очистку диалога, удаление диалога и смену его названия. Бизнес-логика
 * апдейтов (команды, приветствие, заявки, нажатия inline-кнопок) остаётся за
 * слушателем приложения: `message_callback` — это действие пользователя, а не
 * сообщение, поэтому в историю плагин его не пишет.
 *
 * Повторное сохранение безопасно: `storeIncoming` идемпотентен по паре «chat_id +
 * message_id», правка, удаление и очистка уже применённого апдейта повторно ничего
 * не портят. Плагин можно допускать рядом со слушателем приложения — он пишет
 * только свою таблицу `max_chat_messages`.
 */
class HandleIncomingMaxUpdate
{
    public function __construct(
        private readonly MaxMessageService $messages,
    ) {
    }

    public function handle(MaxUpdateReceived $event): void
    {
        match ($event->update->updateType) {
            UpdateType::MessageCreated => $this->messages->storeIncoming($event->update),
            UpdateType::MessageEdited => $this->messages->applyIncomingEdit($event->update),
            UpdateType::MessageRemoved => $this->messages->applyIncomingRemoval($event->update),
            UpdateType::DialogCleared => $this->messages->applyIncomingClear($event->update),
            UpdateType::DialogRemoved => $this->messages->applyIncomingChatRemoval($event->update),
            UpdateType::ChatTitleChanged => $this->messages->applyIncomingTitle($event->update),
            default => null,
        };
    }
}
