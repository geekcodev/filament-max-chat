# v1.0.0

Filament v5 плагин для общения оператора с пользователями MAX-мессенджера.

## Что внутри

Страница `/admin/chat` — лента сообщений, список диалогов, аватарки с инициалами. Оператор может отвечать текстом с
HTML-разметкой или отправлять файлы (фото, видео, аудио, документы).

Историю можно почистить целиком или удалить отдельные сообщения.

Лента обновляется в реальном времени через Echo/Reverb. Если чат открыт на другой вкладке — появится сразу.

На остальных страницах админки работает через пуш:

- бейдж с числом непрочитанных на ссылке «Чат» в сайдбаре
- browser notification
- звуковой сигнал (Web Audio API, без внешних файлов)

Вложения хранятся на приватном диске, отдаются по защищённому роуту. HTML-разметка от оператора фильтруется по белому
списку тегов MAX.

## Требования

- PHP ^8.4
- Laravel 13 / Filament 5
- `geekcodev/laravel-max-client` с опубликованными миграциями

## Установка

```bash
composer require geekcodev/filament-max-chat
php artisan vendor:publish --tag=filament-max-chat-config
```

Подключить плагин в `AdminPanelProvider`:

```php
use GeekCo\FilamentMaxChat\FilamentMaxChatPlugin;

$panel->plugin(FilamentMaxChatPlugin::make());
```

## Конфигурация

Все опции в `config/filament-max-chat.php`:

| Ключ                    | Что делает               | По умолчанию   |
|-------------------------|--------------------------|----------------|
| `permissions.view`      | Кто видит чат            | `chat.view`    |
| `permissions.answer`    | Кто может отвечать       | `chat.answer`  |
| `broadcast_channel`     | Канал Echo               | `chat.channel` |
| `attachments.disk`      | Диск для хранения файлов | `local`        |
| `notifications.enabled` | Пуши на других страницах | `true`         |
| `notifications.sound`   | Звук при новом сообщении | `true`         |
| `notifications.browser` | Browser notification     | `true`         |

## Тесты

135 тестов, 96% покрытие строк. PHPStan level max, PSR-12, `declare(strict_types=1)`.

---

[Laravel](https://laravel.com) · [Filament](https://filamentphp.com) · [laravel-max-client](https://github.com/geekcodev/laravel-max-client)
