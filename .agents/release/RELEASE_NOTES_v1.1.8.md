# RELEASE NOTES v1.1.8

Дата: 2026-10-28

## Новое

- Расширяемость интерфейса чата без копирования Livewire-компонента и Blade-шаблона. Добавлены Blade-стеки
  `filament-max-chat.header-actions-start/end`, `filament-max-chat.formatting-actions-start/end`,
  `filament-max-chat.reply-footer`, `filament-max-chat.chat-footer`.
- Декларативное добавление кнопок через конфиг `filament-max-chat.actions.header` и
  `filament-max-chat.actions.formatting`. Поддержка `url`/`label`/`icon`/`permission`/`visible` (callable/bool),
  передача контекста `$chat`, `$chatUser` (из `$chat->interlocutor()`), `$this`.
- Системная кнопка «Запросить контакт» по умолчанию рядом с кнопками форматирования. Отправляет inline-клавиатуру с
  кнопкой типа `request_contact` через `MaxChatSender::sendRequestContact()`. Метод `OperatorChat::requestContact()`.
- Переводы `request_contact` и `request_contact_button` в `lang/ru/chat.php` и `lang/en/chat.php`.
- Папка `examples/` с примерами использования: конфиг действий, Blade-стеки, наследование Livewire, описание механизма
  `request_contact`.

## Изменение (BC)

- Отсутствует.

## Затронутые сценарии

- Расширение шапки чата и панели форматирования из хост-приложения без копирования шаблона/компонента.
- Запрос контакта у собеседника в диалоге (механизм `request_contact` в MAX).
- Кастомные переходы по роутам хост-приложения с передачей `chat_id`/`user_id`/данных собеседника.

## Качество

- Тесты: добавлен unit-тест `MaxChatSenderTest::send_request_contact_sends_inline_keyboard()`. Всего 369 тестов, 946
  assertions — OK.
- Статика: PHPStan level max — 0 ошибок.
- Линт: php-cs-fixer — 0 файлов с правками.
- Покрытие: сохранено ≥95%.
- Audit: `composer audit` — без уязвимостей.
