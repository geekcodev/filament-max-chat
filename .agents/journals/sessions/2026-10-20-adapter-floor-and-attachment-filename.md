---
tags: [ dependencies, composer, adapter, constraint, core, attachments, filename, security, http-fake, test, gate, v1.1.7 ]
date: 2026-10-20
---

# Пол адаптера ^1.2.3 и настоящее имя файла из вебхука

## Проблема

Пользователь выпустил адаптер `laravel-max-client` v1.2.3 (синхронизация с ядром ^1.1.11, логика без правок) и попросил
обновить composer, посмотреть, есть ли в новом ядре функционал для плагина, и проверить новый функционал. В ядре v1.1.11
появилось поле `Attachment::$filename` — обязательное по спеке MAX поле файла, которое плагин раньше не использовал: имя
угадывалось из URL скачивания, и под служебным `fd.oneme.ru/getfile?...` оператор видел мусор или заглушку `file.bin`.

## Решение

- `composer.json`: `geekcodev/laravel-max-client` `^1.2.2` → `^1.2.3` (прецедент floor-bump 2026-10-16); в контейнере
  `composer update geekcodev/laravel-max-client` поднял пакет. README «Требования» и constraint-абзац + gotcha 1
  AGENTS.md синхронизированы (история порога: реестр 1.2.0 → полоса 1.2.1 → подписка 1.2.2 → синхронизация с ядром
  1.2.3).
- `MaxAttachmentStore`: ветка File `metadataFor()` передаёт `$attachment->filename` в `download()` (5-й параметр
  `?string $fileName = null`); новые `sanitizeFileName()` (basename после замены `\` на `/`, срез control-chars,
  отсечение точек-заглушек, лимит 255) и `extensionFromFileName()`. Приоритет имени: sanitized `filename` → имя из URL →
  заглушка; приоритет расширения: из `filename` → из URL → по MIME → типовое. Путь хранения по-прежнему UUID — имя
  только метаданные карточки (OWASP A08). По спеке `filename` есть только у `FileAttachment` (проверено по schema.yaml),
  поэтому внедрение только в File-ветке.
- `ChatStatus::Suspended` ядра плагину не грозит: используется enum адаптера `MaxChatStatus`, exhaustive-match по core
  `ChatStatus` нет.
- AGENTS.md: gotcha 26 — `Http::fake()` с `sink` отдаёт тело один раз (см. «Тесты»).

## Тесты

Пять новых unit-тестов `MaxAttachmentStoreTest`: настоящее имя поверх URL, санитизация path-компонентов (включая
`\`), control-chars, пустой/dots-only `filename` → фолбэк на имя из URL, расширение без usable extension → из URL. При
написании вскрыт артефакт фейка: `Http::response()` создаёт один promise с одним stream, а
`PendingRequest::sinkStubHandler` читает `getContents()` без rewind — второй скачивание на одном фейке получает пустой
файл и отсекается по `size === 0` (в тесте — метаданные только с `type`). Дефект виден только тесту со двумя
скачиваниями на одном фейке; лечение — closure-форма `Http::fake(fn () => Http::response(...))`, закреплено gotcha 26.
PHPStan в новых тестах потребовал `assertArrayHasKey` перед доступом к опциональным ключам `name`/`path` (сужение в
стиле файла). Итог: 352 теста / 894 проверки, покрытие 97.44% строк (1217/1249).

## Нюансы

Чистый резолв (шаг 7) подтвердил оба пола: `laravel-max-client 1.2.3`, `max-php-client 1.1.11`, Laravel 13.38.0
(локально 13.37.0 — дрейф, ровно за этим следит шаг 7), Filament 5.10.2, Larastan 3.13.0. Релиз v1.1.7 (коммит, merge
`dev → main`, тег, GitHub Release, Packagist) ждёт явного запроса пользователя; deploy в хост-приложении —
`composer update geekcodev/max-php-client geekcodev/laravel-max-client`, миграции и конфиг не меняются. Стикер и опросы
остаются отложенными (шаги 2 и 3 плана follow-up).

## Gate

Прогнан целиком локально и на чистом резолве (`.ci-sim/` без `vendor/` и `composer.lock`, каталог после проверки
удалён). Локально: lint 0/55 · PHPStan level max 0 · 352 теста / 894 проверки · покрытие 97.44% · `composer audit`
чист (`COMPOSER_IPRESOLVE=4`). Шаг 7: `composer install` со свежим резолвом, затем lint 0/55 · PHPStan 0 · 352 теста ·
покрытие 97.44% · audit чист. Коммиты не создавались.
