---
tags: [ migration, index, performance, unread, navigation-badge, octane, postgresql, gate, docs ]
date: 2026-10-12
---

# Индекс непрочитанного под бейдж навигации

## Проблема

На проде приложения-хоста детальные страницы админки «висели»: блоки RelationManager не догружались, контейнер
становился unhealthy и перезапускался, ошибок в логах не было. Причина подтвердилась экспериментом: виноват не
клиентский poll (выставление `FILAMENT_MAX_CHAT_NOTIFICATIONS_POLL_ENABLED=false` не помогло), а серверный счёт
непрочитанного в бейдже навигации панели. `OperatorChat::getNavigationBadge()` вызывает
`MaxMessageService::totalUnreadCount()` — `count(*) where direction = 'in' and read_at is null` — на каждой отрисовке
страницы. Существующие индексы `max_chat_messages` (`max_chat_id, created_at`), (`user_id, chat_id, created_at`) и
(`created_at`) эту выборку не покрывают: счёт шёл последовательным сканом. Когда история выросла, скан занял секунды,
поток запросов исчерпал пул воркеров Octane, `/up` не успевал, контейнер перезапускался
(`FILAMENT_MAX_CHAT_NOTIFICATIONS_ENABLED=false`, гасящий бейдж, зависания убрал).

## Решение

Аддитивная миграция `0000_02_000003_add_unread_index_to_max_chat_messages.php` создаёт составной индекс
`max_chat_messages_unread_index` по `(direction, read_at)`. Он покрывает и бейдж навигации, и JSON-контроллер
непрочитанного; `markRead()` работает по существующему индексу с ведущим `max_chat_id`. Частичный индекс
(`where read_at is null`) компактнее, но не переносим на MySQL, поэтому взят обычный составной. В `up()` и `down()`
стоит guard `Schema::hasIndex`: приложение-хост могло добавить такой же индекс своей миграцией (проектный фикс хоста), и
при обновлении пакета две миграции не должны конфликтовать; повторный запуск тоже не падает. Правка create-миграции
исключена — она уже выполнена на установках.

## Тесты

`MaxChatMessagesSchemaTest` проверяет наличие `max_chat_messages_unread_index` в `Schema::getIndexes()`, удаление в
`down()` и повторное создание в `up()`, а также идемпотентность (`up()` при уже существующем индексе не падает). Тестов
271 / 628 assertion (было 269 / 618).

## Нюансы

- Частичный индекс (`where read_at is null`) отброшен ради переносимости: MySQL его не поддерживает, а пакет ставится и
  туда.
- Guard `Schema::hasIndex` нужен не только для повторного `migrate`: приложение-хост добавляет такой же индекс своей
  проектной миграцией, и при обновлении пакета обе миграции не должны конфликтовать.
- Имя индекса `max_chat_messages_unread_index` задано явно, чтобы пакетная миграция и проектная миграция хоста ссылались
  на один и тот же индекс.

## Gate

Локальный Gate в dev-контейнере: `composer lint` — 0 файлов; `composer analyse` (PHPStan level max) — 0 ошибок;
`composer test` — 271 тест / 628 assertion; `composer coverage` — 98.31% строк (989/1006, порог 95%); `composer
security-audit` — чисто с `COMPOSER_IPRESOLVE=4` (иначе packagist недоступен, gotcha 11). Шаг Gate 7 (чистый резолв в
`.ci-sim/`) не прогонялся: на диске хоста 100% занято, под второй `vendor/` места нет — остаётся за CI. Коммит и тег
v1.1.5 — за пользователем.
