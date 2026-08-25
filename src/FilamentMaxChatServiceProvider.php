<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat;

use GeekCo\FilamentMaxChat\Http\Controllers\ChatAttachmentController;
use GeekCo\FilamentMaxChat\Livewire\OperatorChat as OperatorChatComponent;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class FilamentMaxChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-max-chat.php', 'filament-max-chat');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-max-chat');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'filament-max-chat');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Livewire::component('filament-max-chat', OperatorChatComponent::class);

        $this->registerAttachmentRoute();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filament-max-chat.php' => config_path('filament-max-chat.php'),
            ], 'filament-max-chat-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/filament-max-chat'),
            ], 'filament-max-chat-views');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'filament-max-chat-migrations');

            $this->publishes([
                __DIR__.'/../lang' => lang_path('vendor/filament-max-chat'),
            ], 'filament-max-chat-lang');
        }
    }

    private function registerAttachmentRoute(): void
    {
        if (config()->boolean('filament-max-chat.route.enabled') === false) {
            return;
        }

        Route::get(
            (string) config()->string('filament-max-chat.route.uri'),
            ChatAttachmentController::class,
        )
            ->middleware('web')
            ->name('filament-max-chat.attachment');
    }
}
