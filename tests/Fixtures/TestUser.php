<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Notifications\Notifiable;

/**
 * @property string $name
 * @property string $email
 * @property bool $can_view_chat
 * @property bool $can_answer_chat
 */
class TestUser extends BaseUser
{
    use Notifiable;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'can_view_chat',
        'can_answer_chat',
    ];

    protected function casts(): array
    {
        return [
            'can_view_chat' => 'boolean',
            'can_answer_chat' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
