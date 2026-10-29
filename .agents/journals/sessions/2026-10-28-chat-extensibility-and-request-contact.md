---
tags: [ extensibility, actions, request_contact, stacks, examples, coverage, tests ]
date: 2026-10-28
---

# 2026-10-28-chat-extensibility-and-request-contact

## Проблема

Нужно доработать расширяемость плагина без копирования Livewire/Blade. В хост-приложениях требуется добавлять кнопки
"Запросить контакт" и "Создать заявку" в чате с передачей user_id/chat_id/имени/телефона. Требовалось сделать кнопку
"Запросить контакт" системной (по умолчанию в пакете), добавить возможность задавать роуты извне, добавить примеры, не
забыть про AGENTS.md (файлы сессий и журнал).

## Решение

Добавлены Blade-стеки в шапку, рядом с кнопками форматирования, в футере формы. В Livewire добавлены computed
headerActions/formattingActions и resolveActions с поддержкой callable/url/permission/visible и доступом к MaxChat и
MaxUser через interlocutor (). В конфиг добавлена секция actions (пустые массивы по умолчанию — BC сохранён).

Системная кнопка "Запросить контакт" выведена рядом с кнопками форматирования: при наличии собеседника, отсутствии
телефона и праве chat.answer кнопка видна, при клике уходит inline-клавиатура с кнопкой типа request_contact через
MaxChatSender (добавлен метод sendRequestContact). В Livewire добавлен метод requestContact () с проверкой права,
записью исходящего в историю и обработкой ошибки без раскрытия payload.

Создана папка examples/ с примерами использования через конфиг, через Blade-стеки, через наследование Livewire, а также
пример отправки request_contact. Добавлены переводы request_contact/request_contact_button в lang/ru и lang/en.

## Тесты

`MaxChatSenderTest`: отправка request_contact с подписью и без неё (ветки format). `OperatorChatTest`: успешная отправка
с записью исходящего, кастомный текст кнопки, отказ без chat.answer, ошибка отправки, вызов без активного чата, а также
резолв действий из конфига — callable с контекстом чата, скрытие по permission, скрытие по visible=false, пропуск
пустых/null/не-массивных элементов, не-массивная секция конфига.

После первого прогона CI покрытие упало до 93.93% (ниже порога 95%): новый код был покрыт только unit-тестом. Добавлены
feature-тесты на все ветки requestContact () и resolveActions () — покрытие 97.65% (1415/1449 строк). Локальный vendor
держал Livewire 4.4.7 с advisory GHSA-mhf7-xp6r-6qrc; обновление до 4.4.8 (lock не коммитится) вернуло audit в чистое
состояние, на свежем резолве проблема не воспроизводится.

## Нюансы

Роуты для внешних кнопок принимаются извне, плагин их только рендерит. Данные берутся из MaxChat/MaxUser, $chatUser
может быть null (группы и каналы). Кнопка использует механизм request_contact (inline keyboard) из ядра. Переводы
проверяются в тестах через `__()`: локаль Testbench — en, литерал на русском ломал мок.

## Gate

- `composer lint`: OK (0 файлов).
- `composer analyse`: OK (PHPStan level max, 0 ошибок).
- `composer coverage`: OK (380 тестов, 971 assertion, 97.65% строк).
- `composer security-audit`: OK (0 уязвимостей).
- Шаг 7 (`.ci-sim/` без vendor/ и composer.lock, `composer install` заново): lint 0 · PHPStan max 0 · 380 тестов ·
  покрытие 97.65% · audit чист; каталог удалён.
