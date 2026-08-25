<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Pages;

use GeekCo\FilamentMaxChat\Pages\OperatorChat;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class OperatorChatPageTest extends TestCase
{
    #[Test]
    public function get_title_uses_config_value_when_set(): void
    {
        config()->set('filament-max-chat.ui.title', 'Custom Title');

        $page = new OperatorChat();

        $this->assertSame('Custom Title', $page->getTitle());
    }

    #[Test]
    public function get_title_falls_back_to_translation_when_empty(): void
    {
        config()->set('filament-max-chat.ui.title', '');

        $page = new OperatorChat();

        $title = $page->getTitle();
        $this->assertIsString($title);
        $this->assertNotEmpty($title);
    }

    #[Test]
    public function get_navigation_label_uses_config_value_when_set(): void
    {
        config()->set('filament-max-chat.ui.navigation_label', 'My Chats');

        $this->assertSame('My Chats', OperatorChat::getNavigationLabel());
    }

    #[Test]
    public function get_navigation_label_falls_back_to_translation_when_empty(): void
    {
        config()->set('filament-max-chat.ui.navigation_label', '');

        $label = OperatorChat::getNavigationLabel();
        $this->assertNotEmpty($label);
    }

    #[Test]
    public function get_navigation_badge_returns_null_when_notifications_disabled(): void
    {
        config()->set('filament-max-chat.notifications.enabled', false);

        $this->assertNull(OperatorChat::getNavigationBadge());
    }

    #[Test]
    public function get_navigation_badge_color_returns_danger(): void
    {
        $this->assertSame('danger', OperatorChat::getNavigationBadgeColor());
    }
}
