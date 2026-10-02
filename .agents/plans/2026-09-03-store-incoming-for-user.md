# Доработка пакета `geekcodev/filament-max-chat`

Репозиторий: `filament-max-chat`. Ветка: `dev` → PR в `main` → тег `vX.Y.Z`.

## Проблема

В мини-приложении три сценария, где оператору нужно видеть и получать оповещение о
"пользовательском" действии, но пакет не даёт чистого способа это сделать:

1. **Нажатие "Позвать оператора"** (`message_callback` с payload `{"action":"contact_operator"}`). Хост-приложение
   получает `Update` с профилем пользователя (`$update->user`), но не имеет публичного метода сохранения **входящего**
   пользовательского сообщения с произвольным текстом. Единственный путь — `storeIncoming(Update)`, который читает текст
   из `$update->message->body->text`.
2. **Заявки (leads)** — сохранение формы как входящего сообщения с деталями заявки; профиль пользователя в этом случае
   берётся из реестра `max_users` (в WebAppData имени нет).
3. В обоих случаях нужно, чтобы `max_users` обновлялся профилем (имя чата) и чтобы сообщение считалось **непрочитанным**
   (`direction=In, read_at IS NULL`) — только тогда оператор получает бейдж/звук/уведомление в админке.

Сейчас хост обходит это, вручную конструируя `Update`/`Message`/`MessageBody` и передавая их в
`MaxMessageService::storeIncoming()`. Работает, но это хрупко и семантически неверно.

## Предлагаемая доработка

В `src/Services/MaxMessageService.php` добавить явный публичный метод, сохраняющий **входящее** пользовательское
сообщение с заданным текстом и обновляющий профиль в `max_users`.

```php
/**
 * Состояние входящего пользовательского сообщения с произвольным текстом.
 * Обновляет профиль max_users (имя чата) и сохраняет сообщение как непрочитанное
 * входящее (direction=In, sender=User) — оператор видит его и получает оповещение.
 *
 * @param int      $userId   идентификатор пользователя в MAX
 * @param int      $chatId   идентификатор чата в MAX
 * @param User|null $user    профиль из вебхука; при null резолвится из реестра max_users
 * @param string   $text     текст сообщения (или null — сообщение без текста)
 * @param string|null $messageId  внешний message_id (уникальность/источник)
 */
public function storeIncomingForUser(
    int $userId,
    int $chatId,
    ?User $user,
    ?string $text,
    ?string $messageId = null,
): ?MaxMessage
```

Логика:

- если `$user === null` — резолвить профиль из реестра `max_users` по `$userId`; если профиля нет и переданного `User`
  тоже нет — вернуть `null` (нечего писать, имя чата неизвестно);
- `upsertChat($userId, $chatId, $user)` — как в `storeIncoming()`;
- `createMessage(direction: In, senderType: User, text, messageId)` — как в `storeIncoming()`;
- ничего не отправлять в MAX (только история + broadcast `MaxMessageCreated`).

Резолв профиля из реестра — приватный хелпер `userFromRegistry(int $userId): ?User`, строящий
`GeekCo\MaxPhpClient\Dto\User` из `max_users` (user_id/first_name/last_name/username/is_bot/ last_activity_time).

### Замечания

- Метод **не дублирует** `storeIncoming`/`storeOutgoing`, а переиспользует их внутренние
  `upsertChat`/`createMessage` — сохраняя единую точку записи.
- `createMessage` уже эмитит `MaxMessageCreated` (broadcast `chat-message.created`) — оповещение оператора работает «из
  коробки».
- Обратите внимание: контур оповещений оператора в `notification-script.blade.php` срабатывает только при росте
  `unread_count` (входящие `direction=In` со `read_at IS NULL`) — поэтому
  `direction=In` здесь обязателен.

## Что это закроет в хост-приложении

Хост-приложение сможет заменить текущий обход `app/app/Services/
OperatorChatService.php`, который вручную собирает `Update`/`Message`/`MessageBody`:

```php
// сейчас (обход)
$this->chatMessages->storeIncoming(new Update(...MessageBody(text: $text)...));

// после релиза пакета (чисто)
$this->chatMessages->storeIncomingForUser($userId, $chatId, $update->user, $text);
```

Для заявок — `storeIncomingForUser($userId, $chatId, null, MaxBot::leadChatText($lead), (string)$lead->id)`
(профиль резолвится из `max_users`).

## Тесты

Добавить unit-тесты в `tests/Unit/MaxMessageServiceTest.php`:

- `storeIncomingForUser` сохраняет входящее сообщение `direction=In, sender=User` с текстом;
- обновляет `max_users` именем, когда передан `User`;
- резолвит профиль из `max_users`, когда `User` не передан;
- возвращает `null` без записи, если нет ни `User`, ни записи в `max_users`;
- эмитит `MaxMessageCreated`.

## Gate

После изменений — обязательная последовательность из `AGENTS.md` пакета:

1. `composer run lint` (php-cs-fixer);
2. `composer run format` при правках;
3. `vendor/bin/phpstan analyse` (level max);
4. `vendor/bin/phpunit` (failOnRisky/failOnWarning);
5. `composer run coverage` (≥95%);
6. `composer audit`.

## Чек-лист реализации

- [x] `storeIncomingForUser` в `src/Services/MaxMessageService.php`
  (переиспользует `upsertChat`/`createMessage`, emits `MaxMessageCreated`).
- [x] Приватный хелпер `userFromRegistry(int $userId): ?User` (резолв из `max_users`).
- [x] Unit-тесты `tests/Unit/Services/MaxMessageServiceTest.php`:
  текст `direction=In, sender=User`, обновление `max_users`, резолв из реестра,
  `null` без профиля, `message_id`, dispatch события.
- [x] Gate (lint / phpstan / phpunit / audit).

