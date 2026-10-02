<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Models;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\LaravelMaxClient\Models\MaxChat as BaseMaxChat;
use GeekCo\LaravelMaxClient\Models\MaxChatUser;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Extension of the package chat registry model (laravel-max-client): relations
 * with chat messages and the interlocutor lookup for the operator UI.
 *
 * С v1.2.0 адаптера в max_chats одна строка на чат, первичный ключ — chat_id,
 * а пользователи чата лежат в max_chat_users. Связи maxUser() и колонки user_id
 * здесь больше нет, вместо них interlocutor() поверх унаследованной связи
 * chatUsers(). Название для UI берётся из displayName() модели адаптера.
 *
 * @property int $chat_id
 * @property \GeekCo\LaravelMaxClient\Enums\MaxChatStatus $status
 * @property string|null $title
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property int $unread_count Computed attribute from MaxMessageService::conversations().
 */
class MaxChat extends BaseMaxChat
{
    /** @return HasMany<MaxMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(MaxMessage::class, 'max_chat_id', 'chat_id');
    }

    /** @return HasOne<MaxMessage, $this> */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(MaxMessage::class, 'max_chat_id', 'chat_id')->latestOfMany();
    }

    public function unreadCount(): int
    {
        return $this->messages()
            ->where('direction', MaxMessageDirection::In)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Название чата для интерфейса оператора.
     *
     * Переопределяет метод адаптера, который берёт первого пользователя чата
     * отдельным запросом на каждую строку списка. Здесь используется уже
     * загруженная связь chatUsers, а собеседником считается первый не-бот, а не
     * бот, который добавил бота в чат. Семантика названия совпадает с
     * родителем: у группы и канала это title из getChat().
     */
    public function displayName(): string
    {
        if ($this->isGroup()) {
            $title = $this->title;

            if ($title !== null && $title !== '') {
                return $title;
            }
        }

        $user = $this->interlocutor();

        if ($user !== null) {
            if ($user->name !== null && $user->name !== '') {
                return $user->name;
            }

            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return 'chat ' . $this->chat_id;
    }

    /**
     * Подпись отправителя для чата без собеседника: у канала и группы, чей состав
     * ещё не синхронизирован с MAX, не-ботов в реестре нет, и имя показать нечем.
     */
    public function senderFallbackName(): string
    {
        return match ($this->chat_type) {
            ChatType::Channel => __('filament-max-chat::chat.sender.channel'),
            ChatType::Chat => __('filament-max-chat::chat.sender.group'),
            default => __('filament-max-chat::chat.sender.unknown'),
        };
    }

    /**
     * Собеседник оператора в этом чате: первый пользователь реестра, который не
     * бот. У диалога он один, у группы или канала это тот, чей апдейт завёл чат.
     *
     * Использует уже загруженную связь chatUsers, если она есть, чтобы не
     * порождать запрос на каждую строку списка диалогов.
     */
    public function interlocutor(): ?MaxUser
    {
        if ($this->relationLoaded('chatUsers')) {
            /** @var Collection<int, MaxChatUser> $links */
            $links = $this->getRelation('chatUsers');
        } else {
            /** @var Collection<int, MaxChatUser> $links */
            $links = $this->chatUsers()->with('maxUser')->get();
        }

        foreach ($links as $link) {
            /** @var MaxUser|null $user */
            $user = $link->maxUser;

            if ($user !== null && ! $user->is_bot) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Идентификатор собеседника для исходящих сообщений в MAX. У диалога он
     * обязателен, у группы или канала адресатом будет сам чат.
     */
    public function interlocutorId(): ?int
    {
        return $this->interlocutor()?->user_id;
    }
}
