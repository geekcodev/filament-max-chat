<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests;

use GeekCo\FilamentMaxChat\FilamentMaxChatServiceProvider;
use GeekCo\FilamentMaxChat\Tests\Fixtures\AdminPanelProvider;
use GeekCo\FilamentMaxChat\Tests\Fixtures\TestUser;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../vendor/geekcodev/laravel-max-client/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/Migrations');

        Gate::define(
            'chat.view',
            static fn (TestUser $user): bool => $user->can_view_chat,
        );
        Gate::define(
            'chat.answer',
            static fn (TestUser $user): bool => $user->can_answer_chat,
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            MaxServiceProvider::class,
            FilamentMaxChatServiceProvider::class,
            AdminPanelProvider::class,

            // Livewire — строго после Filament: SupportServiceProvider перебивает
            // биндинг DataStore non-shared bind(), а LivewireServiceProvider::register()
            // закрепляет механизмы через instance(). Иначе хранилище состояния Livewire
            // теряется между вызовами.
            \Livewire\LivewireServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('filament-max-chat.user_model', Fixtures\TestUser::class);

        $app['config']->set('laravel-max-client.api_token', 'test-token');
        $app['config']->set('laravel-max-client.retry.base_delay_seconds', 0.0);
    }
}
