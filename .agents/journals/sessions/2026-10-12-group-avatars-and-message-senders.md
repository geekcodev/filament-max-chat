---
tags: [ ui, avatars, groups, channels, senders, registry, jobs, gate, docs ]
date: 2026-10-12
---

# Аватар группы и автор входящего сообщения в группе

## Проблема

Две жалобы пользователя на страницу `/admin/chat`. В списке чатов показывалась устаревшая аватарка, хотя в реестре уже
лежат актуальные данные. В групповом чате не видно автора входящего сообщения: у каждого сообщения свой отправитель, а
интерфейс брал единственного собеседника чата.

Разбор первой жалобы: у группы и канала аватара пользователя нет — их картинка это иконка чата `max_chats.icon_url` из
`getChat()`. Плагин же везде подставлял аватар собеседника (`chatUsers.maxUser`), поэтому в группе и канале картинка
либо отсутствовала, либо не менялась при обновлении иконки чата.

Разбор второй: `MaxMessageService::storeIncoming` пишет `user_id` отправителя самого сообщения, но blade рисовал
отправителя через `$message->maxChat?->interlocutor()`, то есть первого человека чата. В группе все входящие
подписывались одним и тем же собеседником.

## Решение

**Модель чата.** `MaxChat::avatarUrl()` и `fullAvatarUrl()` возвращают для группы и канала непустой `icon_url`, для
диалога — аватар собеседника (`full_avatar_url ?? avatar_url`). Явная проверка `interlocutor() === null` перед
`??` — иначе PHPStan ругался `nullsafe.neverNull`. Кеш-бюстинг к URL не добавляется: при смене URL браузер сам подтянет
новое изображение, а подпись query-параметра могла бы сломать signed-URL. Основное исправление — источник картинки, а не
версия файла.

**Модель сообщения.** Добавлена связь `MaxMessage::sender()` (`user_id` сообщения → `users.user_id`, модель из
`laravel-max-client.users.model`) и `senderName()`: для исходящих — `null`, для входящих — имя отправителя, а при
отсутствии отправителя — `senderFallbackName()` чата. `MaxMessageService::messagesFor()`/`messagesBefore()` грузят
`['maxChat', 'sender']` вместо `maxChat.chatUsers.maxUser`.

**Лента.** Blade подписывает входящее сообщение отправителем через `$message->sender`/`senderName()`; строку с именем
внутри пузыря показывает только для входящих в группе или канале. Список, шапка и карточка берут аватар через
`avatarUrl()`/`fullAvatarUrl()`.

**Профили.** `ChatProfileRefresher` и `RefreshChatProfilesJob` обновляют профили всех не-бот участников чата
(`participants()`), а не только собеседника: в группе и канале у каждого отправителя свой профиль. Боты исключены,
`resolveUser()` сделан `public` для теста.

## Тесты

`MaxChatTest` (avatarUrl/fullAvatarUrl для группы, диалога, фолбэка), `MaxMessageTest` (связь `sender`, `senderName`
для группы, исходящего и fallback), `ChatProfileRefresherTest` (группа ставит в очередь всех не-бот участников),
`RefreshChatProfilesJobTest` (`resolveUser`, `handle` с `chat_id => null`), `OperatorChatTest` (имя и аватар отправителя
в группе). Итого 268 тестов / 609 assertion.

`MaxUserProfileService` — `final class`, Mockery-мок невозможен: тесты используют реальный сервис, поэтому пользователя
готовят с `avatar_url` + `full_avatar_url` + `profile_checked_at = now()`, чтобы `ensureAvatar()` вышел без сетевого
вызова.

Побочно закрыт дрейф Larastan: на свежем резолве `Schema::getIndexes()`/`getColumns()`/`getForeignKeys()` в
`MaxChatMessagesSchemaTest` отдают точные PHPDoc-типы, и защитные `is_array`/`is_string`/`??` стали «всегда true».
Локальные переменные типизированы как `list<array<string, mixed>>`, поэтому проверки остаются осмысленными и на старом,
и на новом резолве — без правки `ignoreErrors`.

## Нюансы

- В группе профиль собеседника может отсутствовать — картинка всё равно есть из `icon_url`; отсутствие собеседника не
  ошибка.
- Иконка группы и канала живёт в `max_chats.icon_url`, её обновляет адаптер; плагин только читает.

## Gate

- Локально (Filament обновлён до 5.10.1 после `composer update`): lint 0 files · PHPStan max 0 errors · 268 тестов
  зелёные · покрытие 98.31% строк (989/1006) · audit чист (0 уязвимостей, `COMPOSER_IPRESOLVE=4`).
- Имитация CI на чистом резолве (`.ci-sim/`, Filament 5.10.1, Larastan 3.13.0, Laravel 13.35.0,
  `laravel-max-client` 1.2.1): lint 0 · PHPStan 0 · 268 тестов · покрытие 98.31% · audit чист. Каталог удалён.
- До `composer update` локальный audit был красным из-за Filament 5.7.8 (advisory для `<5.8.2`) — тот же дрейф vendor, о
  котором говорит gotcha 14; на чистом резолве предупреждения нет.
