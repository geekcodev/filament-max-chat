<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Listeners;

use GeekCo\FilamentMaxChat\Listeners\HandleIncomingMaxUpdate;
use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Enum\UpdateType;
use Mockery\MockInterface;

class HandleIncomingMaxUpdateTest extends TestCase
{
    public function test_created_update_is_stored_as_incoming(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('storeIncoming')->once()->andReturn(null);
        $this->onlyHistoryCalled($service, 'storeIncoming');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate(UpdateType::MessageCreated));
    }

    public function test_edited_update_is_applied(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('applyIncomingEdit')->once()->andReturn(null);
        $this->onlyHistoryCalled($service, 'applyIncomingEdit');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate(UpdateType::MessageEdited));
    }

    public function test_removed_update_is_applied(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('applyIncomingRemoval')->once()->andReturn(false);
        $this->onlyHistoryCalled($service, 'applyIncomingRemoval');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate(UpdateType::MessageRemoved));
    }

    public function test_cleared_update_is_applied(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('applyIncomingClear')->once()->andReturn(0);
        $this->onlyHistoryCalled($service, 'applyIncomingClear');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate(UpdateType::DialogCleared));
    }

    public function test_removed_chat_update_is_applied(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('applyIncomingChatRemoval')->once()->andReturn(false);
        $this->onlyHistoryCalled($service, 'applyIncomingChatRemoval');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate(UpdateType::DialogRemoved));
    }

    public function test_title_change_update_is_applied(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $service->shouldReceive('applyIncomingTitle')->once()->andReturn(null);
        $this->onlyHistoryCalled($service, 'applyIncomingTitle');

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate(UpdateType::ChatTitleChanged));
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
        $listener->handle($this->receivedUpdate(UpdateType::MessageCallback));
    }

    public function test_unrelated_update_is_ignored(): void
    {
        $service = $this->mock(MaxMessageService::class);
        $this->onlyHistoryCalled($service);

        $listener = new HandleIncomingMaxUpdate($service);
        $listener->handle($this->receivedUpdate(UpdateType::BotAdded));
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

    private function receivedUpdate(UpdateType $type): MaxUpdateReceived
    {
        return new MaxUpdateReceived(new Update(
            updateType: $type,
            timestamp: 1000,
            chatId: 222,
            title: $type === UpdateType::ChatTitleChanged ? 'Название группы' : null,
        ));
    }
}
