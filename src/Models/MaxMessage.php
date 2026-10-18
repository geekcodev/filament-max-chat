<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Models;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-import-type StoredAttachment from \GeekCo\FilamentMaxChat\Services\MaxAttachmentStore
 *
 * @property int $id
 * @property int $max_chat_id
 * @property int|null $user_id null у сообщений чата без собеседника в реестре
 * @property int|null $chat_id
 * @property string|null $message_id
 * @property MaxMessageDirection $direction
 * @property MaxMessageSender $sender_type
 * @property string|null $text
 * @property list<StoredAttachment>|null $attachment
 * @property int|null $operator_id
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read MaxUser|null $sender
 */
class MaxMessage extends Model
{
    protected $table = 'max_chat_messages';

    protected $fillable = [
        'max_chat_id',
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
            'max_chat_id' => 'integer',
            'user_id' => 'integer',
            'chat_id' => 'integer',
            'direction' => MaxMessageDirection::class,
            'sender_type' => MaxMessageSender::class,
            'attachment' => 'array',
            'operator_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Attachment metadata stored for this message (always a list).
     *
     * @return list<StoredAttachment>
     */
    public function attachments(): array
    {
        if (! is_array($this->attachment)) {
            return [];
        }

        return $this->attachment;
    }

    /**
     * Attachment metadata for a given index.
     *
     * @return StoredAttachment|null
     */
    public function attachmentAt(int $index = 0): ?array
    {
        return $this->attachments()[$index] ?? null;
    }

    /**
     * Short text for preview in the conversation list and feed.
     */
    public function previewText(): string
    {
        if (is_string($this->text) && $this->text !== '') {
            return $this->text;
        }

        // Сырое значение колонки читается как mixed: метаданные могли быть
        // записаны в обход стора, поэтому форма проверяется, а не берётся на
        // веру из типа. Вложение без строкового `type` показывается как файл.
        $raw = $this->getAttribute('attachment');
        $attachments = is_array($raw) ? $raw : [];

        if ($attachments === []) {
            return __('filament-max-chat::chat.preview.file');
        }

        $first = $attachments[0] ?? null;
        $type = is_array($first) && is_string($first['type'] ?? null) ? $first['type'] : 'file';

        $label = match ($type) {
            'image' => __('filament-max-chat::chat.preview.image'),
            'video' => __('filament-max-chat::chat.preview.video'),
            'audio' => __('filament-max-chat::chat.preview.audio'),
            'sticker' => __('filament-max-chat::chat.preview.sticker'),
            'contact' => __('filament-max-chat::chat.preview.contact'),
            'location' => __('filament-max-chat::chat.preview.location'),
            'share' => __('filament-max-chat::chat.preview.share'),
            'inline_keyboard' => __('filament-max-chat::chat.preview.buttons'),
            default => __('filament-max-chat::chat.preview.file'),
        };

        return count($attachments) > 1
            ? sprintf('%s +%d', $label, count($attachments) - 1)
            : $label;
    }

    /**
     * С v1.2.0 адаптера первичный ключ max_chats — chat_id, поэтому owner key
     * указывается явно: по умолчанию belongsTo ищет колонку `id`, которой в
     * max_chats больше нет.
     *
     * @return BelongsTo<MaxChat, $this>
     */
    public function maxChat(): BelongsTo
    {
        /** @var class-string<MaxChat> $chatModel */
        $chatModel = config()->string('filament-max-chat.chat_model');

        return $this->belongsTo($chatModel, 'max_chat_id', 'chat_id');
    }

    /**
     * Отображаемое имя отправителя входящего сообщения: из профиля реестра, а
     * если профиля нет — типовая подпись чата («Группа», «Канал»). Для исходящих
     * сообщений имя не показывается.
     */
    public function senderName(): ?string
    {
        if ($this->direction !== MaxMessageDirection::In) {
            return null;
        }

        $user = $this->sender;

        if ($user instanceof MaxUser) {
            if ($user->name !== null && $user->name !== '') {
                return $user->name;
            }

            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return $this->maxChat?->senderFallbackName();
    }

    /** @return BelongsTo<Model, $this> */
    public function operator(): BelongsTo
    {
        /** @var class-string<Model> $operatorModel */
        $operatorModel = config()->string('filament-max-chat.user_model');

        return $this->belongsTo($operatorModel, 'operator_id');
    }

    /**
     * Отправитель входящего сообщения из реестра max_users. Идентификаторы
     * сообщений уникальны внутри чата, а user_id в апдейте указывает на автора,
     * поэтому в группе у каждого сообщения свой отправитель, а не собеседник чата.
     *
     * @return BelongsTo<MaxUser, $this>
     */
    public function sender(): BelongsTo
    {
        /** @var class-string<MaxUser> $userModel */
        $userModel = config()->string('laravel-max-client.users.model', MaxUser::class);

        return $this->belongsTo($userModel, 'user_id', 'user_id');
    }
}
