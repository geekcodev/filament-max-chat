---
tags: [ request_contact, max-api, errors-required, gate, ci-sim ]
date: 2026-10-29
---

# Запрос контакта без текста сообщения (errors.required)

## Проблема

В проде `OperatorChat::requestContact()` падает с `Operator chat: failed to send request_contact to MAX.`, MAX отвечает
`errors.required`. Сообщение уходило только с inline-клавиатурой и без поля `text`: текст кнопки передавался как текст
сообщения, а текст сообщения оставался `null`.

## Решение

- `MaxChatSender::sendRequestContact()`: если текст сообщения пустой, в поле `text` уходит подпись кнопки (простой текст
  без `format`); сигнатура не менялась.
- `OperatorChat::requestContact()`: подпись кнопки и текст сообщения разделены. Текст сообщения —
  `chat.request_contact_prompt`, в историю пишется он же.
- Ключ `request_contact_prompt` добавлен в `lang/ru` и `lang/en`.
- `examples/send-request-contact.md`: пример переписан на `sendRequestContact()`; старый вызов
  `sendAttachments()` с лишними аргументами удалён.

Причина подтверждена по схеме MAX частично: официальная схема допускает `text: null`, поэтому гипотеза «MAX требует
текст при клавиатуре» не проверена на живом API. Исправление защищает от неё в любом случае.

## Тесты

- `MaxChatSenderTest`: тело запроса без текста (подпись кнопки в `text`, без `format`), с текстом и HTML. Запросы
  записываются через собственный middleware в `\ArrayObject`.
- `OperatorChatTest`: `sendRequestContact` получает подпись и текст; в историю пишется текст сообщения.

## Нюансы

- Для PHPStan `Middleware::history()` с передачей массива по ссылке не проходит (тип `array|ArrayAccess`), поэтому
  используется собственный middleware.
- Аудит на `symfony/mime` 8.1.7 показал CVE-2026-45070. Обновлён до v8.1.10 через `composer update symfony/mime
  --with-all-dependencies` (только этот пакет). Файл `composer.lock` не коммитится, изменение касается локального
  резолва; в `composer.json` ничего не менялось.
- `composer audit` дважды упал на таймауте Packagist (curl 28); прошёл с `COMPOSER_IPRESOLVE=4` и повтором.

## Gate

Локально в dev-контейнере: lint 0/60 · PHPStan level max 0 · PHPUnit 381 тестов / 973 assertion · покрытие 97.66%
(1417/1451) · audit чист (после обновления symfony/mime).

Шаг 7 (чистый резолв в `.ci-sim/`, без vendor и composer.lock): lint 0/60 · PHPStan 0 · 381 / 973 · покрытие 97.66% ·
audit чист (с повтором при таймауте). Каталог `.ci-sim/` удалён.

Коммиты не создавались.
