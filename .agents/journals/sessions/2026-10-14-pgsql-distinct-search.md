---
tags: [ search, postgresql, bug, sql, gate, docs ]
date: 2026-10-14
---

# Поиск по диалогам на PostgreSQL падал из-за DISTINCT + ORDER BY

## Проблема

В хост-приложении при поиске по диалогам страница падала с `SQLSTATE[42P10]`: PostgreSQL требует, чтобы выражения в
`ORDER BY` при `SELECT DISTINCT` присутствовали в select list. Запрос первой фазы поиска выбирал
`distinct max_chats.chat_id`, но сортировал по `max_chats.last_activity_at`, которого в выборке нет.
`searchConversations()` в `MaxMessageService` (строка с `orderByDesc('max_chats.last_activity_at')` перед
`distinct()`).

На SQLite дефект не воспроизводится: СУБД не проверяет это правило, поэтому штатный набор тестов был зелёным.

## Решение

Сортировка в запросе идентификаторов чатов убрана. Она была избыточной: `pluck('max_chats.chat_id')` возвращает только
id, а финальная выборка моделей ниже и так пересобирает порядок через `orderByDesc('last_activity_at')`. Убран
`orderByDesc` из `distinct`-запроса, добавлен комментарий с причиной. Публичный API и поведение выдачи не изменились.

## Тесты

Добавлен регрессионный тест
`MaxMessageServiceTest::test_search_conversations_does_not_order_a_distinct_query`: слушает `DB::listen`, собирает SQL и
падает, если в одном запросе встречаются `distinct` и `order by`. Проверено, что при возврате
`orderByDesc` тест красный (SQLite правило не воспроизводит, поэтому проверка идёт по исходному SQL, как в подходе
gotcha 16). Добавлен `test_search_conversations_is_case_insensitive` — поиск регистронезависимый (по имени и по тексту).
Тестов 273 / 633 assertion. Для SQLite в тестах добавлен `mb_strtolower`-based `lower` через
`sqliteCreateFunction`, т.к. встроенный `lower` некорректно работает с UTF-8.

## Нюансы

Правило PostgreSQL о `DISTINCT` + `ORDER BY` не ловится SQLite, поэтому такие запросы нельзя считать проверенными «по
зелёным тестам» — нужен либо явный тест на SQL, либо прогон на реальной PostgreSQL. Регистронезависимый поиск сделан
через `lower()` на БД; для PostgreSQL встроенный `lower` корректен с UTF-8.

## Gate

`composer lint` 0 файлов · `composer analyse` PHPStan max 0 ошибок · `composer test` 273 теста / 633 assertion ·
`composer coverage` 98.31% строк (порог 95%) · `composer audit` чист (`COMPOSER_IPRESOLVE=4`; без флага Packagist из
контейнера недоступен). Шаг 7 (чистый резолв) прогнан в `.ci-sim/`: `composer install` без lock, затем lint 0 · PHPStan
max 0 · покрытие 98.31% · audit чист; каталог удалён. Коммиты не создавались.
