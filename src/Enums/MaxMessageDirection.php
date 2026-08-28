<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Enums;

enum MaxMessageDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => __('filament-max-chat::chat.direction.in'),
            self::Out => __('filament-max-chat::chat.direction.out'),
        };
    }
}
