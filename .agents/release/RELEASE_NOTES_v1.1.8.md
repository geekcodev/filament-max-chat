# v1.1.8

Расширяемость интерфейса чата без копирования Livewire-компонента и Blade-шаблона: стеки, декларативные кнопки в
конфиге и системная кнопка запроса контакта.

## Новое

- Расширяемость интерфейса чата без копирования Livewire-компонента и Blade-шаблона. Добавлены Blade-стеки
  `filament-max-chat.header-actions-start/end`, `filament-max-chat.formatting-actions-start/end`,
  `filament-max-chat.reply-footer`, `filament-max-chat.chat-footer`.
- Декларативное добавление кнопок через конфиг `filament-max-chat.actions.header` и
  `filament-max-chat.actions.formatting`. Поддержка `url`/`label`/`icon`/`permission`/`visible` (callable/bool),
  передача контекста `$chat`, `$chatUser` (из `$chat->interlocutor()`), `$this`. Секции по умолчанию пустые, поведение
  без настроек не меняется.
- Системная кнопка «Запросить контакт» рядом с кнопками форматирования: показывается при наличии собеседника, отсутствии
  телефона и праве `chat.answer`. Отправляет inline-клавиатуру с кнопкой типа `request_contact` через
  `MaxChatSender::sendRequestContact()`, исходящее пишется в историю. Метод `OperatorChat::requestContact()`.
- Переводы `request_contact` и `request_contact_button` в `lang/ru/chat.php` и `lang/en/chat.php`.
- Папка `examples/` с примерами использования: конфиг действий, Blade-стеки, наследование Livewire, описание механизма
  `request_contact`.

## Изменение (BC)

- Отсутствует.

## Затронутые сценарии

- Расширение шапки чата и панели форматирования из хост-приложения без копирования шаблона и компонента.
- Запрос контакта у собеседника в диалоге (механизм `request_contact` в MAX).
- Кастомные переходы по роутам хост-приложения с передачей `chat_id`/`user_id` и данных собеседника.

## Качество

- Тесты: 380 тестов, 971 assertion. Добавлены unit-тесты `MaxChatSender::sendRequestContact()` (с подписью и без)
  и feature-тесты `requestContact()` и `resolveActions()` в `OperatorChatTest`.
- Покрытие: 97.65% строк (1415/1449), порог 95% пройден.
- Статика: PHPStan level max — 0 ошибок.
- Линт: php-cs-fixer — 0 файлов с правками.
- Audit: `composer audit` — без уязвимостей. Локальный vendor обновлён до Livewire 4.4.8 (advisory GHSA-mhf7-xp6r-6qrc),
  на свежем резолве CI проблема не возникает.
- Gate прогнан локально и на чистом резолве в `.ci-sim/` без `vendor/` и `composer.lock`.
