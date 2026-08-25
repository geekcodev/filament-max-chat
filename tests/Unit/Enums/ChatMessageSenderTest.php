<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Enums;

use GeekCo\FilamentMaxChat\Enums\ChatMessageSender;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ChatMessageSenderTest extends TestCase
{
    #[Test]
    public function user_label_returns_translation(): void
    {
        $result = ChatMessageSender::User->label();

        $this->assertNotEmpty($result);
    }

    #[Test]
    public function operator_label_returns_translation(): void
    {
        $result = ChatMessageSender::Operator->label();

        $this->assertNotEmpty($result);
    }

    #[Test]
    public function bot_label_returns_translation(): void
    {
        $result = ChatMessageSender::Bot->label();

        $this->assertNotEmpty($result);
    }
}
