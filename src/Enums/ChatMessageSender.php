<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Enums;

enum ChatMessageSender: string
{
    case User = 'user';
    case Operator = 'operator';
    case Bot = 'bot';

    public function label(): string
    {
        return match ($this) {
            self::User => __('filament-max-chat::chat.sender.user'),
            self::Operator => __('filament-max-chat::chat.sender.operator'),
            self::Bot => __('filament-max-chat::chat.sender.bot'),
        };
    }
}
