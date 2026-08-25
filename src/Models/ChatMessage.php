<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Models;

use GeekCo\FilamentMaxChat\Enums\ChatMessageDirection;
use GeekCo\FilamentMaxChat\Enums\ChatMessageSender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bot_chat_id
 * @property int $user_id
 * @property int|null $chat_id
 * @property string|null $message_id
 * @property ChatMessageDirection $direction
 * @property ChatMessageSender $sender_type
 * @property string|null $text
 * @property array{type: string, path?: string, name?: string, mime?: string, size?: int}|null $attachment
 * @property int|null $operator_id
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ChatMessage extends Model
{
    protected $table = 'chat_messages';

    protected $fillable = [
        'bot_chat_id',
        'user_id',
        'chat_id',
        'message_id',
        'direction',
        'sender_type',
        'text',
        'attachment',
        'operator_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'bot_chat_id' => 'integer',
            'user_id' => 'integer',
            'chat_id' => 'integer',
            'direction' => ChatMessageDirection::class,
            'sender_type' => ChatMessageSender::class,
            'attachment' => 'array',
            'operator_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Short text for preview in the conversation list and feed.
     */
    public function previewText(): string
    {
        if (is_string($this->text) && $this->text !== '') {
            return $this->text;
        }

        $type = is_array($this->attachment) ? ($this->attachment['type'] ?? null) : null;

        return match ($type) {
            'image' => __('filament-max-chat::chat.preview.image'),
            'video' => __('filament-max-chat::chat.preview.video'),
            'audio' => __('filament-max-chat::chat.preview.audio'),
            default => __('filament-max-chat::chat.preview.file'),
        };
    }

    /** @return BelongsTo<BotChat, $this> */
    public function botChat(): BelongsTo
    {
        return $this->belongsTo(BotChat::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function operator(): BelongsTo
    {
        /** @var class-string<Model> $operatorModel */
        $operatorModel = config()->string('filament-max-chat.user_model');

        return $this->belongsTo($operatorModel, 'operator_id');
    }
}
