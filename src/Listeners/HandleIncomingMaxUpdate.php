<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Listeners;

use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Update\ChatTitleChangedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\DialogClearedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\DialogRemovedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageEditedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageRemovedUpdate;

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
        $update = $event->update;

        match (true) {
            $update instanceof MessageCreatedUpdate => $this->messages->storeIncoming($update),
            $update instanceof MessageEditedUpdate => $this->messages->applyIncomingEdit($update),
            $update instanceof MessageRemovedUpdate => $this->messages->applyIncomingRemoval($update),
            $update instanceof DialogClearedUpdate => $this->messages->applyIncomingClear($update),
            $update instanceof DialogRemovedUpdate => $this->messages->applyIncomingChatRemoval($update),
            $update instanceof ChatTitleChangedUpdate => $this->messages->applyIncomingTitle($update),
            default => null,
        };
    }
}
