---
tags: [ max, updates, auto-handle, broadcast, edit, removal, clear, dialog, title, idempotency, config, release-v1.1.6, gate ]
date: 2026-10-14
---

# Автообработка апдейтов MAX и broadcast изменений

## Проблема

В i2tech правка сообщения в MAX не меняла ленту, удалённое сообщение оставалось в истории. Причина правки:
`message_edited` не несёт верхнего `message_id`, идентификатор лежит только в `message.body.mid` (по `max-openapi`:
`UpdateBase` содержит лишь `update_type` и `timestamp`). `findByMaxMessageId()` читал `$update->messageId`, получал
`null` и молча ничего не делал; фикстуры задавали `messageId: 'm-1'` явно, поэтому дефект не воспроизводился. Правка и
удаление не доезжали до открытой ленты без `wire:poll`: echo-подписчик умел только дописывать новые строки. Плагин
апдейты не обрабатывал — это делал слушатель приложения. Не обрабатывались и события уровня диалога: очистка диалога,
удаление диалога и смена названия чата.

## Решение

- `findByMaxMessageId()`: `$update->messageId ?? $update->message?->body?->mid`.
- `storeIncoming()`/`createMessage()`: идемпотентность по паре «`max_chat_id` + `message_id`»; без `message_id` —
  прежнее поведение. Дедупликация стоит **до** скачивания медиа (иначе повторная доставка оставляет файлы-сироты).
- Слушатель `Listeners\HandleIncomingMaxUpdate` (match по `UpdateType`, шесть типов) + регистрация в `boot()` при
  `updates.auto_handle` (новый ключ, по умолчанию `true`; env `FILAMENT_MAX_CHAT_UPDATES_AUTO_HANDLE`):
  `message_created` → `storeIncoming()`, `message_edited` → `applyIncomingEdit()`, `message_removed` →
  `applyIncomingRemoval()`, `dialog_cleared` → `applyIncomingClear()`, `dialog_removed` → `applyIncomingChatRemoval()`,
  `chat_title_changed` → `applyIncomingTitle()`. `message_callback` не обрабатывается (действие пользователя).
- `applyIncomingClear()` чистит локальные строки и файлы вложений **без** вызова MAX API: на стороне MAX переписка уже
  очищена, поэтому `clearHistory()` не годится — он бы повторно рассылал `deleteMessage`.
- `applyIncomingChatRemoval()` помечает чат статусом Removed (история и вложения сохраняются) — как кнопка удаления в
  UI.
- `applyIncomingTitle()` пишет `title` только известному чату, пустое значение игнорируется (так же в
  laravel-max-client).
- События `MaxMessageUpdated` (`chat-message.updated`), `MaxMessageDeleted` (`chat-message.deleted`),
  `MaxMessagesCleared` (`chat-message.cleared`) и `MaxChatRemoved` (`chat-removed`); удаление и очистка несут
  идентификаторы, а не модели. Echo на `updated`/`deleted`/`cleared` → Livewire `chat-reload` (полная перезагрузка
  ленты), на `chat-removed` → `#[On('chat-removed')] handleChatRemoved()` закрывает ленту удалённого чата;
  `chat-refresh` оставлен для новых сообщений.

## Состав релиза v1.1.6 (все изменения ветки)

Состав (сверять `git log v1.1.5..HEAD` и `git status`): коммит `560cd60`
`fix: make chat search case-insensitive and PostgreSQL-safe` (поиск диалогов — `SQLSTATE 42P10` и регистронезависимость)
плюс незакоммиченные правки `src/`, `config/`, `.env.example`, blade-компонента, тестов и docs по этой сессии.

## Тесты

`HandleIncomingMaxUpdateTest` (маршрутизация всех шести типов и игнор `message_callback`), фолбэк `message.body.mid`,
идемпотентность (включая повторную доставку с вложением без второго скачивания), dispatch/`broadcastAs` четырёх событий,
регистрация слушателя вкл/выкл, clear/removal/title, Livewire `reload` и `chat-removed`. Фикстуры
`MaxMessageServiceTest` — уникальные `mid`. 306 тестов / 752 assertion.

## Нюансы

- Дублирование с собственным слушателем приложения безопасно за счёт идемпотентности; правка/удаление/очистка/удаление
  диалога повторно ничего не портят. Хосту с единственным источником — выключить `updates.auto_handle`.
- `message_removed` несёт верхний `message_id` (фолбэк не нужен), но код берёт общий путь — это только упрощает.
- Лента на правку/удаление/очистку перечитывается целиком: дописывание строк не отражает изменение уже показанных.
- `dialog_cleared`, как `message_edited` и `message_removed`, должен быть включён в webhook-подписку MAX; жёсткий список
  `MaxSubscribeCommand::updateTypes()` в адаптере его не содержит — отдельная задача в `../laravel-max-client`.
- Номер релиза: пользователь выбрал **v1.1.6** для всей партии. Формально смена поведения по умолчанию тянет на минор —
  отмечено в плане и release notes.

## Gate

Локально (docker, PHP 8.4.12, Xdebug 3.4.7) и на шаге 7 (чистый резолв в `.ci-sim/` без `vendor/` и `composer.lock`) —
оба зелёные: lint 0 · PHPStan max 0 · 306 тестов / 752 assertion · покрытие 97.96% строк (1103/1126) · `composer audit`
чист. Резолв: Laravel 13.36.0, Filament 5.10.2, Livewire 4.4.7, Larastan 3.13.0, laravel-max-client 1.2.1,
max-php-client 1.1.9. Аудит на шаге 7 прошёл со второй попытки — первый вызов упал на сети (`curl error 28` до
packagist.org, gotcha 11), а не на уязвимостях. Каталог `.ci-sim/` удалён. Коммиты и теги не создавались.
