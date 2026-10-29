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
use GeekCo\MaxPhpClient\Dto\AttachmentRequest;
use GeekCo\MaxPhpClient\Dto\InlineKeyboardButtonRow;
use GeekCo\MaxPhpClient\Dto\InlineKeyboardButton;
use GeekCo\MaxPhpClient\Enum\AttachmentType;
use GeekCo\MaxPhpClient\Enum\ButtonType;

$recipient = new Recipient(chatId: $chatId);
$button = new InlineKeyboardButton(type: ButtonType::RequestContact, text: 'Поделиться контактом');
$row = new InlineKeyboardButtonRow([$button]);
$attachment = AttachmentRequest::create(AttachmentType::InlineKeyboard, rows: [$row]);

app(MaxChatSender::class)->sendAttachments($recipient, [], [], null, [$attachment]);
// Или добавить метод sendRequestContact() - см. реализацию в пакете
```

## Через Livewire компонент

Кнопка уже добавлена в UI. При клике вызывается метод `requestContact()` компонента `OperatorChat`.
