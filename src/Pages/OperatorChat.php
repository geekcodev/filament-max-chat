<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use Illuminate\Contracts\Support\Htmlable;

class OperatorChat extends Page
{
    protected string $view = 'filament-max-chat::pages.operator-chat';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(
            config()->string('filament-max-chat.permissions.view', 'chat.view'),
        );
    }

    public function getTitle(): string|Htmlable
    {
        $title = config('filament-max-chat.ui.title');

        return is_string($title) && $title !== ''
            ? $title
            : __('filament-max-chat::chat.page.title');
    }

    public static function getNavigationLabel(): string
    {
        $label = config('filament-max-chat.ui.navigation_label');

        return is_string($label) && $label !== ''
            ? $label
            : __('filament-max-chat::chat.page.title');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        /** @var string|\UnitEnum|null */
        return config('filament-max-chat.ui.navigation_group');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return config()->string('filament-max-chat.ui.navigation_icon');
    }

    public static function getNavigationSort(): ?int
    {
        return config()->integer('filament-max-chat.ui.navigation_sort');
    }

    public static function getNavigationBadge(): ?string
    {
        if (config()->boolean('filament-max-chat.notifications.enabled') === false) {
            return null;
        }

        $count = app(MaxMessageService::class)->totalUnreadCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return config()->string('filament-max-chat.ui.slug', 'chat');
    }
}
