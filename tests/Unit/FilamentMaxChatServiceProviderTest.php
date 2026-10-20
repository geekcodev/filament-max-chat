<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit;

use GeekCo\FilamentMaxChat\FilamentMaxChatServiceProvider;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use ReflectionClass;

class FilamentMaxChatServiceProviderTest extends TestCase
{
    public function test_routes_are_registered_when_enabled(): void
    {
        $this->assertTrue(Route::has('filament-max-chat.attachment'));
        $this->assertTrue(Route::has('filament-max-chat.unread-count'));
    }

    public function test_incoming_update_listener_is_registered_when_enabled(): void
    {
        Event::forget(MaxUpdateReceived::class);

        $provider = new FilamentMaxChatServiceProvider(app());
        $this->invokePrivate($provider, 'registerIncomingUpdateListener');

        $this->assertTrue(Event::hasListeners(MaxUpdateReceived::class));
    }

    public function test_incoming_update_listener_is_skipped_when_disabled(): void
    {
        Event::forget(MaxUpdateReceived::class);
        config()->set('filament-max-chat.updates.auto_handle', false);

        $provider = new FilamentMaxChatServiceProvider(app());
        $this->invokePrivate($provider, 'registerIncomingUpdateListener');

        $this->assertFalse(Event::hasListeners(MaxUpdateReceived::class));
    }

    public function test_attachment_route_is_skipped_when_disabled(): void
    {
        config()->set('filament-max-chat.route.enabled', false);

        $provider = new FilamentMaxChatServiceProvider(app());

        $collection = Route::getRoutes();
        $this->assertInstanceOf(\Illuminate\Routing\RouteCollection::class, $collection);
        $before = $collection->count();

        $this->invokePrivate($provider, 'registerAttachmentRoute');
        $this->invokePrivate($provider, 'registerUnreadCountRoute');

        $after = $collection->count();

        $this->assertSame($before, $after);
    }

    public function test_unread_email_schedule_is_registered_when_enabled(): void
    {
        config()->set('filament-max-chat.email.enabled', true);

        $provider = new FilamentMaxChatServiceProvider(app());
        $this->invokePrivate($provider, 'registerUnreadEmailSchedule');

        // Счёт не фиксируется: провайдер уже зарегистрировал свой callback на
        // старте приложения, и при разрешённом Schedule событие добавится от него.
        $this->assertGreaterThanOrEqual(1, $this->scheduledNotifyUnreadCommands());
    }

    public function test_unread_email_schedule_is_skipped_when_disabled(): void
    {
        $provider = new FilamentMaxChatServiceProvider(app());
        $this->invokePrivate($provider, 'registerUnreadEmailSchedule');

        $this->assertSame(0, $this->scheduledNotifyUnreadCommands());
    }

    private function scheduledNotifyUnreadCommands(): int
    {
        return collect(app(Schedule::class)->events())
            ->map(static fn (ScheduleEvent $event): string => is_string($event->command) ? $event->command : '')
            ->filter(static fn (string $command): bool => str_contains($command, 'max-chat:notify-unread'))
            ->count();
    }

    private function invokePrivate(object $object, string $method): void
    {
        $reflection = new ReflectionClass($object);
        $reflection->getMethod($method)->invoke($object);
    }
}
