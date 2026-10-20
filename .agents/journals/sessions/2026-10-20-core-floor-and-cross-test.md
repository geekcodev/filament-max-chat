---
tags: [ dependencies, composer, core, constraint, contact, hash, webhook, test, gate, release, v1.1.7 ]
date: 2026-10-20
---

# Пол ядра ^1.1.11 и сквозной тест contact-без-hash

## Проблема

Шаг 1 плана `2026-10-19-contact-hash-followup.md`: после публикации ядра `max-php-client` v1.1.11 (2026-10-20,
Packagist) нужно поднять пол зависимости, закрепить фикс сквозным тестом и прогнать Gate — контакт без `hash` в webhook
обязан доходить до ленты оператора.

## Решение

- `composer.json`: `geekcodev/max-php-client` `^1.1.10` → `^1.1.11` (`laravel-max-client` не менялся — его `^1.1.10`
  сам подхватывает 1.1.11); в контейнере `composer update geekcodev/max-php-client` поднял пакет до 1.1.11.
- README: раздел «Требования» объясняет, почему минимум теперь 1.1.11 (толерантный nullable-`hash` контакта); строка
  v1.1.7 добавлена в «Историю изменений».
- `tests/Feature/ContactWithoutHashWebhookTest`: контакт без `hash` идёт реальным путём доставки — сырой JSON вебхука →
  `WebhookHandler::decode()` ядра → событие `MaxUpdateReceived` → слушатель плагина → `storeIncoming()` → в `attachment`
  карточка `contact` с именем и телефоном, без ключа `hash`. Раньше такой webhook терялся ещё на декодировании.
- `.agents/`: release-notes v1.1.7 (в работе), план follow-up переведён в «шаг 1 выполнен», запись в активном плане.

## Тесты

Новый feature-тест зелёный (8 assertions). Сводно: PHPUnit 347 тестов / 872 проверки, покрытие 97.41% строк (1204/1236,
порог 95%).

## Нюансы

Чистый резолв (шаг 7) подтвердил пол: `max-php-client 1.1.11`, Laravel 13.37.0, Filament 5.10.2, Larastan 3.13.0,
адаптер 1.2.2. Релиз v1.1.7 (коммит, merge `dev → main`, тег, GitHub Release, Packagist) ждёт явного запроса
пользователя; deploy в хост-приложении — `composer update geekcodev/max-php-client`, миграции и конфиг не меняются.
Открытыми остаются шаги 2 (стикер — гипотеза о stub-поведении MAX проверяется вторым стикером) и 3 (опросы — терпимость
к неизвестным типам в ядре) плана follow-up.

## Gate

Прогнан целиком локально и на чистом резолве (`.ci-sim/` без `vendor/` и `composer.lock`, каталог после проверки
удалён). Локально: lint 0/55 · PHPStan level max 0 · 347 тестов / 872 проверки · покрытие 97.41% · `composer audit`
чист (`COMPOSER_IPRESOLVE=4`). Шаг 7: `composer install` со свежим резолвом, затем lint 0 · PHPStan 0 · 347 тестов ·
покрытие 97.41% · audit чист. Коммиты не создавались.
