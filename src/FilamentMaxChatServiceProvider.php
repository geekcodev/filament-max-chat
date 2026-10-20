<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat;

use GeekCo\FilamentMaxChat\Console\MaxChatUnreadNotificationCommand;
use GeekCo\FilamentMaxChat\Console\MaxChatUpgradeCommand;
use GeekCo\FilamentMaxChat\Http\Controllers\MaxAttachmentController;
use GeekCo\FilamentMaxChat\Http\Controllers\UnreadCountController;
use GeekCo\FilamentMaxChat\Listeners\HandleIncomingMaxUpdate;
use GeekCo\FilamentMaxChat\Livewire\OperatorChat as OperatorChatComponent;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
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
        $this->registerUnreadCountRoute();
        $this->registerIncomingUpdateListener();
        $this->registerUnreadEmailSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([
                MaxChatUpgradeCommand::class,
                MaxChatUnreadNotificationCommand::class,
            ]);

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
            (string) config()->string('filament-max-chat.route.uri').'/{index}',
            MaxAttachmentController::class,
        )
            ->middleware('web')
            ->name('filament-max-chat.attachment');
    }

    private function registerUnreadCountRoute(): void
    {
        if (config()->boolean('filament-max-chat.route.enabled') === false) {
            return;
        }

        Route::get(
            (string) config()->string('filament-max-chat.route.unread_count_uri'),
            UnreadCountController::class,
        )
            ->middleware('web')
            ->name('filament-max-chat.unread-count');
    }

    private function registerIncomingUpdateListener(): void
    {
        if (! config()->boolean('filament-max-chat.updates.auto_handle', true)) {
            return;
        }

        Event::listen(MaxUpdateReceived::class, HandleIncomingMaxUpdate::class);
    }

    /**
     * Рассылка непрочитанного в планировщик хоста: пока email.enabled включён,
     * команда max-chat:notify-unread ставится в расписание каждую минуту без
     * правок schedule хост-приложения. Проверка конфига — в момент разрешения
     * Schedule (старт консольного ядра): так событие видит и .env, и кэшированный
     * конфиг, а выключенное по умолчанию расписание не создаётся вовсе.
     */
    private function registerUnreadEmailSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config()->boolean('filament-max-chat.email.enabled') === false) {
                return;
            }

            $schedule->command('max-chat:notify-unread')->everyMinute();
        });
    }
}
