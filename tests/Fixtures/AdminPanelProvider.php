<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use GeekCo\FilamentMaxChat\FilamentMaxChatPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            // Filament v5 требует default-панель: getUrl() страниц без явного
            // panel резолвит именно её (иначе NoDefaultPanelSetException).
            ->default()
            ->plugin(FilamentMaxChatPlugin::make());
    }
}
