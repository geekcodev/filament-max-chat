# Отправка кнопки "Запросить контакт" (request_contact)

В пакете по умолчанию кнопка добавляется автоматически, если:

- Выбран активный чат с собеседником
- У собеседника отсутствует телефон
- Пользователь имеет право `chat.answer`

Механизм использует inline-клавиатуру с кнопкой типа `request_contact`. После нажатия пользователем на кнопку в MAX ему
предложат поделиться контактом. Если контакт валиден и принадлежит пользователю, адаптер `laravel-max-client` может
сохранить телефон в `max_users.phone` (в зависимости от настроек).

## Ручная отправка через MaxChatSender

```php
use GeekCo\FilamentMaxChat\Services\MaxChatSender;
use GeekCo\MaxPhpClient\Dto\Recipient;

$recipient = new Recipient(chatId: $chatId, userId: $userId);

app(MaxChatSender::class)->sendRequestContact(
    recipient: $recipient,
    buttonText: 'Поделиться контактом',
    text: 'Пожалуйста, поделитесь контактом.',
);
```

Сообщение с клавиатурой без текста MAX отклоняет (`errors.required`). Если `text` не передан, в качестве текста
сообщения уходит подпись кнопки.

## Через Livewire компонент

Кнопка уже добавлена в UI. При клике вызывается метод `requestContact()` компонента `OperatorChat`.
