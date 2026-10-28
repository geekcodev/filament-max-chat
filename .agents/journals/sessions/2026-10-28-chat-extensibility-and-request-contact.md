---
tags: [ extensibility, actions, request_contact, stacks, examples, tests ]
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
MaxUser через interlocutor (). В конфиг добавлена секция actions.

Системная кнопка "Запросить контакт" вынесена в компонент/конфиг по умолчанию: при наличии chatUser, отсутствии телефона
и праве chat.answer добавляется кнопка, при клике отправляется inline-клавиатура с кнопкой типа request_contact через
MaxChatSender (добавлен метод sendRequestContact). В шаблоне добавлена обработка действия request-contact через wire:
click.

Создана папка examples/ с примерами использования через конфиг, через Blade-стеки, через наследование Livewire, а также
пример отправки request_contact.

Добавлены переводы и базовые тесты расширяемости.

## Тесты

Запущены lint, PHPStan, PHPUnit. Тесты проходят.

## Нюансы

Роуты для внешних кнопок принимаются извне. Данные берутся из MaxChat/MaxUser. Кнопка запросить контакт использует
механизм request_contact (inline keyboard) из ядра.

## Gate

composer lint: OK  
composer analyse: OK  
composer test: OK (368 тестов)  
