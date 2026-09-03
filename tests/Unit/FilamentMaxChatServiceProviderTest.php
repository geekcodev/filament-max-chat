<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit;

use GeekCo\FilamentMaxChat\FilamentMaxChatServiceProvider;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use ReflectionClass;

class FilamentMaxChatServiceProviderTest extends TestCase
{
    public function test_routes_are_registered_when_enabled(): void
    {
        $this->assertTrue(Route::has('filament-max-chat.attachment'));
        $this->assertTrue(Route::has('filament-max-chat.unread-count'));
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

    private function invokePrivate(object $object, string $method): void
    {
        $reflection = new ReflectionClass($object);
        $reflection->getMethod($method)->invoke($object);
    }
}
