<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use GeekCo\FilamentMaxChat\Pages\OperatorChat;
use Illuminate\Contracts\View\View as ViewContract;

class FilamentMaxChatPlugin implements Plugin
{
    public static function make(): static
    {
        /** @var static */
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filament-max-chat';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([OperatorChat::class]);
    }

    public function boot(Panel $panel): void
    {
        $panel->renderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            static fn (): ViewContract => \Illuminate\Support\Facades\View::make(
                'filament-max-chat::components.notification-script',
            ),
        );
    }
}
