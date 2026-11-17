<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Listeners;

use GeekCo\FilamentMaxChat\Listeners\HandleIncomingMaxUpdate;
use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Callback;
use GeekCo\MaxPhpClient\Dto\Message;
use GeekCo\MaxPhpClient\Dto\MessageBody;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\Update\BotAddedToChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\ChatTitleChangedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\DialogClearedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\DialogRemovedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCallbackUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageEditedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageRemovedUpdate;
use GeekCo\MaxPhpClient\Dto\User;
use Mockery\MockInterface;

class HandleIncomingMaxUpdateTest extends TestCase
{
    public function test_created_update_is_stored_as_incoming(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('storeIncoming')->once()->andReturn(null);
        $this->onlyHistoryCalled($service, 'storeIncoming');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate('message_created'));
    }

    public function test_edited_update_is_applied(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('applyIncomingEdit')->once()->andReturn(null);
        $this->onlyHistoryCalled($service, 'applyIncomingEdit');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate('message_edited'));
    }

    public function test_removed_update_is_applied(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('applyIncomingRemoval')->once()->andReturn(false);
        $this->onlyHistoryCalled($service, 'applyIncomingRemoval');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate('message_removed'));
    }

    public function test_cleared_update_is_applied(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('applyIncomingClear')->once()->andReturn(0);
        $this->onlyHistoryCalled($service, 'applyIncomingClear');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate('dialog_cleared'));
    }

    public function test_removed_chat_update_is_applied(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('applyIncomingChatRemoval')->once()->andReturn(false);
        $this->onlyHistoryCalled($service, 'applyIncomingChatRemoval');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate('dialog_removed'));
    }

    public function test_title_change_update_is_applied(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('applyIncomingTitle')->once()->andReturn(null);
        $this->onlyHistoryCalled($service, 'applyIncomingTitle');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate('chat_title_changed'));
    }

    /**
     * Нажатие inline-кнопки — действие пользователя, а не сообщение: в историю
     * плагин его не пишет, это зона бизнес-логики приложения.
     */
    public function test_message_callback_is_ignored(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $this->onlyHistoryCalled($service);

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate('message_callback'));
    }

    public function test_unrelated_update_is_ignored(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $this->onlyHistoryCalled($service);

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate('bot_added'));
    }

    /**
     * Запрещает сервису все методы истории, кроме перечисленных: так тест ловит
     * не только «нужный метод вызван», но и «обработан лишний тип апдейта».
     */
    private function onlyHistoryCalled(MaxMessageService&MockInterface $service, string ...$allowed): void
    {
        $methods = [
            'storeIncoming',
            'applyIncomingEdit',
            'applyIncomingRemoval',
            'applyIncomingClear',
            'applyIncomingChatRemoval',
            'applyIncomingTitle',
        ];

        foreach ($methods as $method) {
            if (in_array($method, $allowed, true)) {
                continue;
            }

            $service->shouldNotReceive($method);
        }
    }

    private function receivedUpdate(string $type): MaxUpdateReceived
    {
        $user = new User(userId: 111, firstName: 'Иван', lastName: null, username: null, isBot: false);

        $update = match ($type) {
            'message_created' => new MessageCreatedUpdate(timestamp: 1000, message: $this->message($user)),
            'message_edited' => new MessageEditedUpdate(timestamp: 1000, message: $this->message($user)),
            'message_removed' => new MessageRemovedUpdate(
                timestamp: 1000,
                chatId: 222,
                messageId: 'm-1',
                userId: 111,
            ),
            'message_callback' => new MessageCallbackUpdate(
                timestamp: 1000,
                callback: new Callback(callbackId: 'cb-1', user: $user),
                message: $this->message($user),
            ),
            'dialog_cleared' => new DialogClearedUpdate(timestamp: 1000, chatId: 222, user: $user),
            'dialog_removed' => new DialogRemovedUpdate(timestamp: 1000, chatId: 222, user: $user),
            'chat_title_changed' => new ChatTitleChangedUpdate(
                timestamp: 1000,
                chatId: 222,
                user: $user,
                title: 'Название группы',
            ),
            'bot_added' => new BotAddedToChatUpdate(timestamp: 1000, chatId: 222, user: $user, isChannel: false),
            default => throw new \LogicException(sprintf('Unsupported update type in test: %s', $type)),
        };

        return new MaxUpdateReceived($update);
    }

    private function message(User $user): Message
    {
        return new Message(
            sender: $user,
            recipient: new Recipient(chatId: 222, userId: 111),
            timestamp: 1000,
            body: new MessageBody(mid: 'm-1', seq: 1, text: 'Текст'),
        );
    }
}
