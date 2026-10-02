<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Services;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Enums\MaxMessageSender;
use GeekCo\FilamentMaxChat\Events\MaxMessageCreated;
use GeekCo\FilamentMaxChat\Models\MaxChat;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Services\MaxMessageService;
use GeekCo\FilamentMaxChat\Services\MaxChatSender;
use GeekCo\FilamentMaxChat\Tests\Fixtures\TestUser;
use GeekCo\FilamentMaxChat\Tests\Support\MakesChats;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxUser as RegistryMaxUser;
use GeekCo\MaxPhpClient\Dto\Attachment;
use GeekCo\MaxPhpClient\Dto\ImageAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\Message;
use GeekCo\MaxPhpClient\Dto\MessageBody;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\User as MaxUser;
use GeekCo\MaxPhpClient\Enum\AttachmentType;
use GeekCo\MaxPhpClient\Enum\ChatType;
use GeekCo\MaxPhpClient\Enum\UpdateType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class MaxMessageServiceTest extends TestCase
{
    use MakesChats;
    use RefreshDatabase;

    public function test_store_incoming_creates_message_and_chat(): void
    {
        $message = app(MaxMessageService::class)->storeIncoming($this->incomingUpdate('Привет!'));

        $this->assertNotNull($message);
        $this->assertDatabaseHas('max_chat_messages', [
            'id' => $message->id,
            'direction' => MaxMessageDirection::In->value,
            'sender_type' => MaxMessageSender::User->value,
            'text' => 'Привет!',
            'user_id' => 111,
            'chat_id' => 222,
        ]);

        $maxChat = MaxChat::query()->first();
        $this->assertNotNull($maxChat);
        $this->assertSame(MaxChatStatus::Active, $maxChat->status);
        $this->assertNotNull($maxChat->last_activity_at);
        $this->assertDatabaseHas('max_users', [
            'user_id' => 111,
            'first_name' => 'Иван',
        ]);
        $this->assertSame($maxChat->chat_id, $message->max_chat_id);
        $this->assertDatabaseHas('max_chat_users', ['chat_id' => 222, 'user_id' => 111]);
    }

    public function test_chat_exists_by_chat_id(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Привет!'));

        $maxChat = MaxChat::query()->first();
        $this->assertNotNull($maxChat);

        $this->assertTrue($service->chatExists(222));
        $this->assertFalse($service->chatExists(999999));

        // Устаревший алиас остаётся рабочим: идентификатор записи реестра
        // совпадает с chat_id.
        $this->assertSame(222, $service->resolveInternalIdFromMaxChatId($maxChat->chat_id));
        $this->assertNull($service->resolveInternalIdFromMaxChatId(999999));
    }

    public function test_remove_chat_marks_status_as_removed(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Привет!'));

        $maxChat = MaxChat::query()->first();
        $this->assertNotNull($maxChat);
        $this->assertSame(MaxChatStatus::Active, $maxChat->status);

        $result = $service->removeChat($maxChat->chat_id);

        $this->assertTrue($result);
        $this->assertSame(MaxChatStatus::Removed, $maxChat->fresh()?->status);
    }

    public function test_remove_chat_returns_false_for_missing_chat(): void
    {
        $service = app(MaxMessageService::class);

        $this->assertFalse($service->removeChat(999999));
    }

    public function test_store_incoming_updates_existing_chat_name(): void
    {
        $service = app(MaxMessageService::class);

        $service->storeIncoming($this->incomingUpdate('Первое'));
        $service->storeIncoming($this->incomingUpdate('Второе'));

        $this->assertSame(1, MaxChat::query()->count());
        $this->assertSame(2, MaxMessage::query()->count());
    }

    public function test_store_incoming_returns_null_without_chat(): void
    {
        $service = app(MaxMessageService::class);

        $this->assertNull($service->storeIncoming(new Update(
            updateType: UpdateType::MessageCreated,
            timestamp: 1000,
            user: $this->maxUser(),
            message: new Message(
                sender: $this->maxUser(),
                recipient: new Recipient(chatId: null, userId: 111),
                timestamp: 1000,
                body: new MessageBody(mid: 'm-2', seq: 1, text: 'Без chat'),
            ),
        )));
        $this->assertDatabaseCount('max_chat_messages', 0);
    }

    public function test_store_outgoing_creates_out_message_with_operator(): void
    {
        $operator = TestUser::query()->create([
            'name' => 'Оператор',
            'email' => 'operator@example.test',
            'password' => 'password',
        ]);

        $message = app(MaxMessageService::class)->storeOutgoing(
            userId: 111,
            chatId: 222,
            text: 'Добрый день!',
            sender: MaxMessageSender::Operator,
            operatorId: $operator->id,
        );

        $this->assertNotNull($message);
        $this->assertDatabaseHas('max_chat_messages', [
            'id' => $message->id,
            'direction' => MaxMessageDirection::Out->value,
            'sender_type' => MaxMessageSender::Operator->value,
            'text' => 'Добрый день!',
            'operator_id' => $operator->id,
        ]);
        $messageOperator = $message->operator;

        $this->assertNotNull($messageOperator);
        $this->assertSame($messageOperator->getKey(), $operator->getKey());
    }

    public function test_store_incoming_for_user_creates_incoming_message_with_text(): void
    {
        $message = app(MaxMessageService::class)->storeIncomingForUser(
            userId: 111,
            chatId: 222,
            user: $this->maxUser(),
            text: 'Заявка #42: замена крана',
        );

        $this->assertNotNull($message);
        $this->assertDatabaseHas('max_chat_messages', [
            'id' => $message->id,
            'direction' => MaxMessageDirection::In->value,
            'sender_type' => MaxMessageSender::User->value,
            'text' => 'Заявка #42: замена крана',
            'user_id' => 111,
            'chat_id' => 222,
        ]);
        $this->assertNull($message->read_at);
        $this->assertNull($message->operator_id);
        $this->assertSame(1, MaxChat::query()->count());
    }

    public function test_store_incoming_for_user_updates_max_users_name_from_passed_user(): void
    {
        app(MaxMessageService::class)->storeIncomingForUser(
            userId: 111,
            chatId: 222,
            user: $this->maxUser(),
            text: 'Заявка',
        );

        $this->assertDatabaseHas('max_users', [
            'user_id' => 111,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'username' => 'ivan_p',
        ]);
    }

    public function test_store_incoming_for_user_resolves_profile_from_registry(): void
    {
        RegistryMaxUser::query()->create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'username' => 'ivan_p',
            'is_bot' => false,
        ]);

        $message = app(MaxMessageService::class)->storeIncomingForUser(
            userId: 111,
            chatId: 222,
            user: null,
            text: 'Заявка без профиля в вебхуке',
        );

        $this->assertNotNull($message);
        $this->assertDatabaseHas('max_chat_messages', [
            'id' => $message->id,
            'direction' => MaxMessageDirection::In->value,
            'text' => 'Заявка без профиля в вебхуке',
            'user_id' => 111,
            'chat_id' => 222,
        ]);
    }

    public function test_store_incoming_for_user_returns_null_without_user_or_registry(): void
    {
        $result = app(MaxMessageService::class)->storeIncomingForUser(
            userId: 111,
            chatId: 222,
            user: null,
            text: 'Без профиля',
        );

        $this->assertNull($result);
        $this->assertDatabaseCount('max_chat_messages', 0);
        $this->assertDatabaseCount('max_chats', 0);
    }

    public function test_store_incoming_for_user_stores_message_id(): void
    {
        $message = app(MaxMessageService::class)->storeIncomingForUser(
            userId: 111,
            chatId: 222,
            user: $this->maxUser(),
            text: 'Заявка',
            messageId: 'lead-42',
        );

        $this->assertNotNull($message);
        $this->assertSame('lead-42', $message->message_id);
    }

    public function test_store_incoming_for_user_dispatches_created_event(): void
    {
        \Illuminate\Support\Facades\Event::fake([MaxMessageCreated::class]);

        $message = app(MaxMessageService::class)->storeIncomingForUser(
            userId: 111,
            chatId: 222,
            user: $this->maxUser(),
            text: 'Заявка',
        );

        $this->assertNotNull($message);

        \Illuminate\Support\Facades\Event::assertDispatched(
            MaxMessageCreated::class,
            static fn (MaxMessageCreated $event): bool => $event->message->is($message),
        );
    }

    public function test_conversations_returns_chats_with_unread_and_last_message(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Первое'));
        $service->storeIncoming($this->incomingUpdate('Второе'));
        $reply = $service->storeOutgoing(111, 222, 'Ответ', MaxMessageSender::Bot);

        $conversations = $service->conversations();

        $this->assertCount(1, $conversations);
        $conversation = $conversations->first();

        $this->assertNotNull($conversation);

        $lastMessage = $conversation->lastMessage;

        $this->assertNotNull($lastMessage);

        $this->assertSame(2, $conversation->unread_count);
        $this->assertSame('Ответ', $lastMessage->text);
        $this->assertSame($reply?->id, $lastMessage->id);
        $this->assertNotNull($conversation->last_activity_at);
        $this->assertSame('Иван Петров', $conversation->displayName());
    }

    public function test_conversations_excludes_non_active_chats(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Привет'));

        MaxChat::query()->firstOrFail()->update(['status' => MaxChatStatus::Stopped]);

        $this->assertCount(0, $service->conversations());
    }

    public function test_store_incoming_without_sender_keeps_message_and_chat(): void
    {
        $message = app(MaxMessageService::class)->storeIncoming($this->channelPostUpdate('Пост в канале'));

        $this->assertNotNull($message);
        $this->assertNull($message->user_id);
        $this->assertSame('Пост в канале', $message->text);
        $this->assertDatabaseHas('max_chat_messages', [
            'id' => $message->id,
            'chat_id' => -777,
            'direction' => MaxMessageDirection::In->value,
            'text' => 'Пост в канале',
        ]);

        $chat = MaxChat::query()->whereKey(-777)->first();
        $this->assertNotNull($chat);
        $this->assertSame(MaxChatStatus::Active, $chat->status);
        $this->assertNull($chat->interlocutorId());
        $this->assertSame(1, app(MaxMessageService::class)->totalUnreadCount());
    }

    public function test_store_incoming_without_chat_is_skipped(): void
    {
        $update = new Update(
            updateType: UpdateType::MessageCreated,
            timestamp: 1000,
            user: $this->maxUser(),
        );

        $this->assertNull(app(MaxMessageService::class)->storeIncoming($update));
    }

    public function test_store_outgoing_without_interlocutor_keeps_message(): void
    {
        $service = app(MaxMessageService::class);
        $chat = $this->makeChat(-777, ['chat_type' => ChatType::Channel]);

        $message = $service->storeOutgoing(userId: null, chatId: $chat->chat_id, text: 'Ответ в канал', sender: MaxMessageSender::Operator);

        $this->assertNotNull($message);
        $this->assertNull($message->user_id);
        $this->assertSame(MaxMessageDirection::Out->value, $message->direction->value);
        $this->assertDatabaseHas('max_chat_messages', [
            'id' => $message->id,
            'max_chat_id' => -777,
            'user_id' => null,
            'text' => 'Ответ в канал',
        ]);
    }

    public function test_search_conversations_finds_chat_without_members_by_message_text(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->channelPostUpdate('Анонс встречи'));

        $found = $service->searchConversations('Анонс');

        $this->assertCount(1, $found);
        $this->assertSame(-777, $found->first()?->chat_id);
    }

    public function test_sender_fallback_name_depends_on_chat_type(): void
    {
        app()->setLocale('ru');

        $channel = $this->makeChat(-777, ['chat_type' => ChatType::Channel]);
        $group = $this->makeChat(555, ['chat_type' => ChatType::Chat]);
        $dialog = $this->makeChat(222, ['chat_type' => ChatType::Dialog]);

        $this->assertSame('Канал', $channel->senderFallbackName());
        $this->assertSame('Группа', $group->senderFallbackName());
        $this->assertSame('Без отправителя', $dialog->senderFallbackName());
    }

    public function test_search_conversations_finds_by_user_name(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Любой текст'));

        $found = $service->searchConversations('Петр');

        $this->assertCount(1, $found);

        $chat = $found->first();
        $this->assertNotNull($chat);
        $this->assertSame('Иван Петров', $chat->displayName());
    }

    public function test_search_conversations_finds_by_message_text(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Хочу купить дрон'));

        $found = $service->searchConversations('дрон');

        $this->assertCount(1, $found);
        $this->assertNotNull($found->first()?->lastMessage);
    }

    public function test_search_conversations_returns_empty_when_no_match(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Просто сообщение'));

        $this->assertCount(0, $service->searchConversations('несуществующий'));
    }

    public function test_search_conversations_returns_empty_for_blank_term(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Сообщение'));

        $this->assertTrue($service->searchConversations('   ')->isEmpty());
    }

    public function test_search_conversations_excludes_non_active_chats(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Ищу по имени Петр'));

        MaxChat::query()->firstOrFail()->update(['status' => MaxChatStatus::Removed]);

        $this->assertCount(0, $service->searchConversations('Петр'));
    }

    public function test_search_conversations_returns_one_chat_once_per_relation_match(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Петр'));
        $service->storeIncoming($this->incomingUpdate('Петр снова'));
        $service->storeOutgoing(111, 222, 'Петр ответ', MaxMessageSender::Bot);

        $found = $service->searchConversations('Петр');

        $this->assertCount(1, $found);
    }

    public function test_search_conversations_treats_percent_as_literal(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Скидка 50% сегодня'));

        $this->assertCount(1, $service->searchConversations('50%'));
        $this->assertCount(0, $service->searchConversations('50today'));
    }

    public function test_search_conversations_treats_underscore_as_literal(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Промокод HOLIDAY_2026'));

        $this->assertCount(1, $service->searchConversations('HOLIDAY_2026'));
        $this->assertCount(0, $service->searchConversations('HOLIDAYX2026'));
    }

    public function test_messages_for_returns_chronological_messages(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Первое'));
        $service->storeOutgoing(111, 222, 'Ответ', MaxMessageSender::Operator);
        $service->storeIncoming($this->incomingUpdate('Третье'));

        $maxChat = MaxChat::query()->firstOrFail();

        $messages = $service->messagesFor($maxChat->chat_id);

        $this->assertSame(['Первое', 'Ответ', 'Третье'], $messages->pluck('text')->all());
    }

    public function test_mark_read_updates_only_incoming_unread(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Первое'));
        $service->storeOutgoing(111, 222, 'Ответ', MaxMessageSender::Operator);

        $maxChat = MaxChat::query()->firstOrFail();
        $service->markRead($maxChat->chat_id);

        $this->assertSame(1, MaxMessage::query()->whereNull('read_at')->count());
        $incoming = MaxMessage::query()->where('direction', MaxMessageDirection::In)->firstOrFail();
        $this->assertNotNull($incoming->read_at);
    }

    public function test_chat_message_created_event_is_broadcast_on_private_channel(): void
    {
        $service = app(MaxMessageService::class);
        $message = $service->storeIncoming($this->incomingUpdate('Привет'));

        $this->assertNotNull($message);

        $event = new MaxMessageCreated($message);
        $this->assertSame('chat-message.created', $event->broadcastAs());
        $this->assertSame('private-chat.channel', $event->broadcastOn()[0]->name);
    }

    public function test_store_incoming_downloads_image_attachment(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $update = $this->incomingUpdateWithAttachments([
            new Attachment(
                type: AttachmentType::Image,
                payload: new ImageAttachmentPayload(url: 'https://cdn.max.ru/photo.jpg'),
            ),
        ]);

        $message = app(MaxMessageService::class)->storeIncoming($update);

        $this->assertNotNull($message);

        $attachments = $message->attachments();
        $this->assertCount(1, $attachments);
        $attachment = $attachments[0];
        $this->assertArrayHasKey('mime', $attachment);
        $this->assertArrayHasKey('size', $attachment);
        $this->assertArrayHasKey('path', $attachment);
        $this->assertSame('image', $attachment['type']);
        $this->assertSame('image/jpeg', $attachment['mime']);
        $this->assertSame(strlen('jpeg-bytes'), $attachment['size']);
        $this->assertMatchesRegularExpression('/\.jpe?g$/', $attachment['path']);

        Storage::disk('local')->assertExists($attachment['path']);
    }

    public function test_store_incoming_without_media_attachment_keeps_attachment_null(): void
    {
        Storage::fake('local');

        $update = $this->incomingUpdateWithAttachments([
            new Attachment(type: AttachmentType::InlineKeyboard, payload: []),
        ]);

        $message = app(MaxMessageService::class)->storeIncoming($update);

        $this->assertNotNull($message);
        $this->assertSame([], $message->attachment);
    }

    /**
     * @param  list<Attachment>  $attachments
     */
    private function incomingUpdateWithAttachments(array $attachments): Update
    {
        return new Update(
            updateType: UpdateType::MessageCreated,
            timestamp: 1000,
            user: $this->maxUser(),
            chatId: 222,
            message: new Message(
                sender: $this->maxUser(),
                recipient: new Recipient(chatId: 222, userId: 111),
                timestamp: 1000,
                body: new MessageBody(mid: 'm-1', seq: 1, text: null, attachments: $attachments),
            ),
        );
    }

    public function test_total_unread_count_returns_zero_when_no_messages(): void
    {
        $service = app(MaxMessageService::class);

        $this->assertSame(0, $service->totalUnreadCount());
    }

    public function test_total_unread_count_counts_incoming_unread_messages(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Привет'));
        $service->storeIncoming($this->incomingUpdate('Ещё одно'));

        $this->assertSame(2, $service->totalUnreadCount());
    }

    public function test_total_unread_count_excludes_read_messages(): void
    {
        $service = app(MaxMessageService::class);
        $message = $service->storeIncoming($this->incomingUpdate('Прочитанное'));
        $this->assertNotNull($message);

        $service->markRead($message->max_chat_id);

        $this->assertSame(0, $service->totalUnreadCount());
    }

    public function test_total_unread_count_excludes_outgoing_messages(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Входящее'));
        $service->storeOutgoing(
            userId: 111,
            chatId: 222,
            text: 'Исходящее',
            sender: MaxMessageSender::Operator,
            operatorId: 1,
        );

        $this->assertSame(1, $service->totalUnreadCount());
    }

    public function test_broadcast_with_includes_unread_count(): void
    {
        $service = app(MaxMessageService::class);
        $message = $service->storeIncoming($this->incomingUpdate('Тест'));
        $this->assertNotNull($message);

        $event = new MaxMessageCreated($message);
        $data = $event->broadcastWith();

        $this->assertSame(1, $data['unread_count']);
        $this->assertSame($message->id, $data['id']);
        $this->assertSame($message->max_chat_id, $data['max_chat_id']);
    }

    public function test_messages_before_returns_older_messages(): void
    {
        $service = app(MaxMessageService::class);
        $first = $service->storeIncoming($this->incomingUpdate('Первое'));
        $second = $service->storeIncoming($this->incomingUpdate('Второе'));
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $older = $service->messagesBefore($first->max_chat_id, $second->id);

        $this->assertCount(1, $older);
        $this->assertSame($first->id, $older->first()?->id);
    }

    public function test_messages_before_returns_empty_when_no_older(): void
    {
        $service = app(MaxMessageService::class);
        $message = $service->storeIncoming($this->incomingUpdate('Единственное'));
        $this->assertNotNull($message);

        $older = $service->messagesBefore($message->max_chat_id, $message->id);

        $this->assertCount(0, $older);
    }

    public function test_delete_message_removes_from_db_and_max(): void
    {
        $sender = $this->mock(MaxChatSender::class);
        $sender->shouldReceive('deleteMessage')->once()->with('msg-123');

        $service = app(MaxMessageService::class);
        $message = $service->storeIncoming($this->incomingUpdate('Удаляемое'));
        $this->assertNotNull($message);

        MaxMessage::query()->where('id', $message->id)->update(['message_id' => 'msg-123']);

        $result = $service->deleteMessage($message->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('max_chat_messages', ['id' => $message->id]);
    }

    public function test_delete_message_returns_false_when_not_found(): void
    {
        $service = app(MaxMessageService::class);

        $result = $service->deleteMessage(999999);

        $this->assertFalse($result);
    }

    public function test_delete_message_still_removes_locally_when_max_fails(): void
    {
        $sender = $this->mock(MaxChatSender::class);
        $sender->shouldReceive('deleteMessage')->once()->andThrow(new \RuntimeException('fail'));

        $service = app(MaxMessageService::class);
        $message = $service->storeIncoming($this->incomingUpdate('Ошибочное'));
        $this->assertNotNull($message);

        MaxMessage::query()->where('id', $message->id)->update(['message_id' => 'msg-err']);

        $result = $service->deleteMessage($message->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('max_chat_messages', ['id' => $message->id]);
    }

    public function test_clear_history_deletes_all_messages(): void
    {
        $sender = $this->mock(MaxChatSender::class);
        $sender->shouldReceive('deleteMessage')->twice();

        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Первое'));
        $service->storeIncoming($this->incomingUpdate('Второе'));

        $chatMessage = MaxMessage::query()->first();
        $this->assertNotNull($chatMessage);

        MaxMessage::query()->update(['message_id' => 'msg-x']);

        $deleted = $service->clearHistory($chatMessage->max_chat_id);

        $this->assertSame(2, $deleted);
        $this->assertDatabaseCount('max_chat_messages', 0);
    }

    public function test_clear_history_succeeds_when_max_delete_fails(): void
    {
        $sender = $this->mock(MaxChatSender::class);
        $sender->shouldReceive('deleteMessage')->once()->andThrow(new \RuntimeException('forbidden'));

        $service = app(MaxMessageService::class);
        $message = $service->storeIncoming($this->incomingUpdate('С ошибкой'));
        $this->assertNotNull($message);

        MaxMessage::query()->where('id', $message->id)->update(['message_id' => 'msg-fail']);

        $deleted = $service->clearHistory($message->max_chat_id);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseCount('max_chat_messages', 0);
    }

    public function test_clear_history_skips_messages_without_message_id(): void
    {
        $sender = $this->mock(MaxChatSender::class);
        $sender->shouldReceive('deleteMessage')->never();

        $service = app(MaxMessageService::class);
        $message = $service->storeIncoming($this->incomingUpdate('Без ID'));
        $this->assertNotNull($message);

        MaxMessage::query()->where('id', $message->id)->update(['message_id' => null]);

        $deleted = $service->clearHistory($message->max_chat_id);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseCount('max_chat_messages', 0);
    }

    public function test_apply_incoming_edit_rewrites_text_of_stored_message(): void
    {
        $service = app(MaxMessageService::class);
        $message = $service->storeIncoming($this->incomingUpdate('Привет'));
        $this->assertNotNull($message);

        $edited = $service->applyIncomingEdit(
            $this->messageChangeUpdate(UpdateType::MessageEdited, 'm-1', 'Привет, как дела?'),
        );

        $this->assertNotNull($edited);
        $this->assertSame('Привет, как дела?', $edited->text);
        $this->assertDatabaseHas('max_chat_messages', [
            'id' => $message->id,
            'text' => 'Привет, как дела?',
        ]);
        $this->assertDatabaseCount('max_chat_messages', 1);
    }

    public function test_apply_incoming_edit_falls_back_to_caption(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Фото'));

        $edited = $service->applyIncomingEdit(
            $this->messageChangeUpdate(UpdateType::MessageEdited, 'm-1', null, 'Новое подпись'),
        );

        $this->assertNotNull($edited);
        $this->assertSame('Новое подпись', $edited->text);
    }

    public function test_apply_incoming_edit_ignores_unknown_message(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Привет'));

        $this->assertNull($service->applyIncomingEdit(
            $this->messageChangeUpdate(UpdateType::MessageEdited, 'm-unknown', 'Правка'),
        ));
        $this->assertDatabaseCount('max_chat_messages', 1);
        $this->assertDatabaseHas('max_chat_messages', ['text' => 'Привет']);
    }

    public function test_apply_incoming_edit_ignores_message_of_another_chat(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Привет'));

        $update = $this->messageChangeUpdate(UpdateType::MessageEdited, 'm-1', 'Правка');
        $this->assertNull($service->applyIncomingEdit(
            new Update(
                updateType: $update->updateType,
                timestamp: $update->timestamp,
                chatId: 999,
                messageId: 'm-1',
            ),
        ));
        $this->assertDatabaseHas('max_chat_messages', ['text' => 'Привет']);
    }

    public function test_apply_incoming_edit_without_identifiers_is_skipped(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Привет'));

        $this->assertNull($service->applyIncomingEdit(new Update(
            updateType: UpdateType::MessageEdited,
            timestamp: 1000,
            chatId: 222,
        )));
        $this->assertNull($service->applyIncomingEdit(new Update(
            updateType: UpdateType::MessageEdited,
            timestamp: 1000,
            messageId: 'm-1',
        )));
        $this->assertDatabaseHas('max_chat_messages', ['text' => 'Привет']);
    }

    public function test_apply_incoming_edit_without_new_text_keeps_stored_text(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Привет'));

        $this->assertNull($service->applyIncomingEdit(
            $this->messageChangeUpdate(UpdateType::MessageEdited, 'm-1', null),
        ));
        $this->assertDatabaseHas('max_chat_messages', ['text' => 'Привет']);
    }

    public function test_apply_incoming_removal_deletes_message_and_attachment(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $service = app(MaxMessageService::class);
        $message = $service->storeIncoming($this->incomingUpdateWithAttachments([
            new Attachment(
                type: AttachmentType::Image,
                payload: new ImageAttachmentPayload(url: 'https://cdn.max.ru/photo.jpg'),
            ),
        ]));
        $this->assertNotNull($message);

        $attachment = $message->attachments()[0];
        $this->assertArrayHasKey('path', $attachment);
        $path = $attachment['path'];
        Storage::disk('local')->assertExists($path);

        $this->assertTrue($service->applyIncomingRemoval(
            $this->messageChangeUpdate(UpdateType::MessageRemoved, 'm-1', null),
        ));

        $this->assertDatabaseCount('max_chat_messages', 0);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_apply_incoming_removal_ignores_unknown_and_unidentified_messages(): void
    {
        $service = app(MaxMessageService::class);
        $service->storeIncoming($this->incomingUpdate('Привет'));

        $this->assertFalse($service->applyIncomingRemoval(
            $this->messageChangeUpdate(UpdateType::MessageRemoved, 'm-unknown', null),
        ));
        $this->assertFalse($service->applyIncomingRemoval(new Update(
            updateType: UpdateType::MessageRemoved,
            timestamp: 1000,
            chatId: 222,
        )));
        $this->assertDatabaseCount('max_chat_messages', 1);
    }

    private function messageChangeUpdate(
        UpdateType $type,
        string $messageId,
        ?string $text,
        ?string $caption = null,
    ): Update {
        return new Update(
            updateType: $type,
            timestamp: 2000,
            chatId: 222,
            messageId: $messageId,
            message: new Message(
                sender: $this->maxUser(),
                recipient: new Recipient(chatId: 222, userId: 111),
                timestamp: 2000,
                body: new MessageBody(mid: $messageId, seq: 1, text: $text, caption: $caption),
            ),
        );
    }

    private function incomingUpdate(string $text): Update
    {
        return new Update(
            updateType: UpdateType::MessageCreated,
            timestamp: 1000,
            user: $this->maxUser(),
            chatId: 222,
            message: new Message(
                sender: $this->maxUser(),
                recipient: new Recipient(chatId: 222, userId: 111),
                timestamp: 1000,
                body: new MessageBody(mid: 'm-1', seq: 1, text: $text),
            ),
        );
    }

    /**
     * Пост в канале: отправителя в апдейте нет, у канала нет и участников.
     */
    private function channelPostUpdate(string $text): Update
    {
        return new Update(
            updateType: UpdateType::MessageCreated,
            timestamp: 1000,
            chatId: -777,
            isChannel: true,
            message: new Message(
                sender: null,
                recipient: new Recipient(chatId: -777, chatType: ChatType::Channel->value),
                timestamp: 1000,
                body: new MessageBody(mid: 'p-1', seq: 1, text: $text),
            ),
        );
    }

    private function maxUser(): MaxUser
    {
        return new MaxUser(
            userId: 111,
            firstName: 'Иван',
            lastName: 'Петров',
            username: 'ivan_p',
            isBot: false,
            lastActivityTime: 1000,
        );
    }
}
