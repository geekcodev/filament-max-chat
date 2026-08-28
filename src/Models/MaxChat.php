<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Models;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\LaravelMaxClient\Models\MaxChat as BaseMaxChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Extension of the package chat registry model (laravel-max-client): relations with chat messages.
 *
 * @property int $user_id
 * @property int|null $chat_id
 * @property \GeekCo\LaravelMaxClient\Enums\MaxChatStatus $status
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property int $unread_count Computed attribute from MaxMessageService::conversations().
 */
class MaxChat extends BaseMaxChat
{
    /** @return BelongsTo<MaxUser, $this> */
    public function maxUser(): BelongsTo
    {
        return $this->belongsTo(MaxUser::class, 'user_id', 'user_id');
    }

    /** @return HasMany<MaxMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(MaxMessage::class, 'max_chat_id');
    }

    /** @return HasOne<MaxMessage, $this> */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(MaxMessage::class, 'max_chat_id')->latestOfMany();
    }

    public function unreadCount(): int
    {
        return $this->messages()
            ->where('direction', MaxMessageDirection::In)
            ->whereNull('read_at')
            ->count();
    }

    public function conversationName(): string
    {
        $user = $this->maxUser;

        $name = trim(implode(' ', array_filter([
            $user?->first_name,
            $user?->last_name,
        ])));

        $userId = $this->user_id;

        return $name !== '' ? $name : __('filament-max-chat::chat.fallback_user', ['id' => $userId]);
    }
}
