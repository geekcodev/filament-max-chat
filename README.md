# filament-max-chat

Filament-плагин: **чат оператора** с пользователями MAX-мессенджера. Строится поверх
[`geekcodev/laravel-max-client`](https://github.com/geekcodev/laravel-max-client)
(реестр чатов `max_bot_chats`/`max_users`) и [`geekcodev/max-php-client`](https://github.com/geekcodev/max-openapi)
(API MAX).

Возможности:

- список активных диалогов (непрочитанные — бейджем), лента сообщений, отметки «прочитано»;
- ответы оператора: HTML-форматирование (тулбар), вложения (фото/видео/аудио/файлы) через `uploadMedia`;
- входящие медиа скачиваются в приватное хранилище и отдаются через авторизованный роут;
- real-time обновления через Echo/Reverb (private-канал) + fallback `wire:poll`;
- права настраиваются строками (`chat.view` / `chat.answer` по умолчанию) — совместимо со spatie/laravel-permission и
  Gate;
- всё (канал broadcast, диск вложений, лимиты, навигация, slug страницы) — в конфиге.

## Требования

- PHP ^8.4, Laravel ^13.0
- Filament ^5.0 (панель v5), Livewire ^4.1
- `geekcodev/laravel-max-client` ^1.0.9 + `geekcodev/max-php-client` ^1.0.9
- Опубликованные миграции laravel-max-client (`max_users`, `max_bot_chats`)
- Для real-time: совместимый broadcaster (например, Laravel Reverb) и `window.Echo` в панели

## Установка

```bash
composer require geekcodev/filament-max-chat
php artisan migrate    # миграция chat_messages загружается автоматически из пакета
```

Подключение к панели:

```php
use GeekCo\FilamentMaxChat\FilamentMaxChatPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(FilamentMaxChatPlugin::make());
}
```

Подключение стилей (Tailwind) — добавьте в `resources/css/filament.css`:

```css
@import 'vendor/geekcodev/filament-max-chat/resources/css/filament-max-chat.css';
```

Права (пример со spatie/laravel-permission):

```php
Role::findByName('operator')->givePermissionTo(['chat.view', 'chat.answer']);
```

## Приём входящих сообщений

Плагин хранит историю: вызывайте `ChatMessageService::storeIncoming()` из своего обработчика апдейтов (событие
`MaxUpdateReceived` пакета laravel-max-client):

```php
use GeekCo\FilamentMaxChat\Services\ChatMessageService;

class HandleMaxUpdateListener
{
    public function __construct(private ChatMessageService $chatMessages) {}

    public function handle(MaxUpdateReceived $event): void
    {
        if ($event->update->updateType === UpdateType::MessageCreated) {
            $this->chatMessages->storeIncoming($event->update);
        }
    }
}
```

Регистрация слушателя — документация laravel-max-client.

## Конфигурация

```bash
php artisan vendor:publish --tag=filament-max-chat-config   # config/filament-max-chat.php
php artisan vendor:publish --tag=filament-max-chat-views    # views для правки UI
```

Ключевые параметры:

| Ключ                                      | По умолчанию                                      | Описание                                                                |
|-------------------------------------------|---------------------------------------------------|-------------------------------------------------------------------------|
| `permissions.view` / `permissions.answer` | `chat.view` / `chat.answer`                       | Права на просмотр чата / отправку ответов                               |
| `broadcast_channel`                       | `operator.chat`                                   | Private-канал события `chat-message.created`                            |
| `bot_chat_model`                          | пакетный `Models\BotChat`                         | Модель диалога (расширение BotChat клиента); переопределяйте подклассом |
| `user_model`                              | `Illuminate\Foundation\Auth\User`                 | Модель оператора для связи `operator_id`                                |
| `attachments.*`                           | `local`, `chat-attachments`, 25 MiB               | Диск, каталог, лимиты, mime-список                                      |
| `route.*`                                 | вкл., `/admin/chat/messages/{message}/attachment` | Роут отдачи вложений и URL редиректа гостя                              |
| `ui.*`                                    | см. конфиг                                        | Иконка/подпись/sort/slug навигации, интервал poll, лимит сообщений      |

## Архитектура

- `Services\ChatMessageService` — история сообщений (`storeIncoming`, `storeOutgoing`, `conversations`, `markRead`);
- `Services\MaxChatSender` — отправка ответов (`sendFormatted`, `sendAttachment`) через ApiClient;
- `Services\ChatAttachmentStore` — приватное хранение вложений (метаданные в JSON-колонке);
- `Support\TextSanitizer` — санитизация HTML под whitelist тегов MAX (TextFormat: html);
- `Events\ChatMessageCreated` — ShouldBroadcast в private-канал;
- `Livewire\OperatorChat` (алиас `filament-max-chat`) + `Pages\OperatorChat`.

Модель `Models\BotChat` расширяет `GeekCo\LaravelMaxClient\Models\BotChat` связями
`messages`/`lastMessage`/`maxUser` и работает с той же таблицей `max_bot_chats` — реестр чатов клиента
(`MAX_CHATS_ENABLED`) продолжает работать без изменений.

## Тестирование и разработка

PHP/Composer на хосте не требуются — всё через Docker (образ `ghcr.io/geekcodev/php:8.4-bookworm`, Orchestra Testbench):

```bash
docker compose up -d --build   # контейнер app (PHP 8.4)
docker compose run --rm app composer install
docker compose exec app composer test       # PHPUnit (SQLite in-memory)
docker compose exec app composer analyse    # PHPStan level max (Larastan)
docker compose exec app composer lint       # PHP-CS-Fixer (--dry-run)
docker compose exec app composer format     # PHP-CS-Fixer (исправить)
docker compose exec app composer audit      # composer audit
```

Xdebug включён по умолчанию (`XDEBUG_MODE=coverage`); для профилирования/отладки подключайтесь к
`host.docker.internal:9003`.
