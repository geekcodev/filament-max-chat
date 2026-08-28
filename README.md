# filament-max-chat

Filament-плагин: **чат оператора** с пользователями MAX-мессенджера. Строится поверх
[`geekcodev/laravel-max-client`](https://github.com/geekcodev/laravel-max-client)
(реестр чатов `max_chats`/`max_users`) и [`geekcodev/max-php-client`](https://github.com/geekcodev/max-openapi)
(API MAX).

Возможности:

- список активных диалогов (непрочитанные — бейджем), лента сообщений, отметки «прочитано»;
- ответы оператора: HTML-форматирование (тулбар), вложения (фото/видео/аудио/файлы) через `uploadMedia`;
- входящие медиа скачиваются в приватное хранилище и отдаются через авторизованный роут;
- real-time обновления через Echo/Reverb (private-канал) + fallback `wire:poll`;
- глобальные уведомления и счётчик непрочитанного на всех страницах панели: Echo + HTTP-poll фолбэк (опционально,
  `notifications.poll_enabled`, интервал `notifications.poll_interval_seconds`);
- права настраиваются строками (`chat.view` / `chat.answer` по умолчанию) — совместимо со spatie/laravel-permission и
  Gate;
- всё (канал broadcast, диск вложений, лимиты, навигация, slug страницы) — в конфиге.

## Требования

- PHP ^8.4, Laravel ^13.0
- Filament ^5.0 (панель v5), Livewire ^4.1
- `geekcodev/laravel-max-client` ^1.1.0 + `geekcodev/max-php-client` ^1.0.9
- Опубликованные миграции laravel-max-client (`max_users`, `max_chats`)
- Для real-time: совместимый broadcaster (например, Laravel Reverb) и `window.Echo` в панели

## Установка

```bash
composer require geekcodev/filament-max-chat
php artisan migrate    # миграция max_chat_messages загружается автоматически из пакета
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

Плагин хранит историю: вызывайте `MaxMessageService::storeIncoming()` из своего обработчика апдейтов (событие
`MaxUpdateReceived` пакета laravel-max-client):

```php
use GeekCo\FilamentMaxChat\Services\MaxMessageService;

class HandleMaxUpdateListener
{
    public function __construct(private MaxMessageService $chatMessages) {}

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

| Ключ                                      | По умолчанию                                                                  | Описание                                                                |
|-------------------------------------------|-------------------------------------------------------------------------------|-------------------------------------------------------------------------|
| `permissions.view` / `permissions.answer` | `chat.view` / `chat.answer`                                                   | Права на просмотр чата / отправку ответов                               |
| `broadcast_channel`                       | `chat.channel`                                                                | Private-канал события `chat-message.created`                            |
| `chat_model`                              | пакетный `Models\MaxChat`                                                     | Модель диалога (расширение MaxChat клиента); переопределяйте подклассом |
| `user_model`                              | `Illuminate\Foundation\Auth\User`                                             | Модель оператора для связи `operator_id`                                |
| `attachments.*`                           | `local`, `chat-attachments`, 25 MiB                                           | Диск, каталог, лимиты, mime-список                                      |
| `route.*`                                 | вкл., `/admin/chat/messages/{message}/attachment`, `/admin/chat/unread-count` | Роуты отдачи вложений и счётчика непрочитанного, URL редиректа гостя    |
| `ui.*`                                    | см. конфиг                                                                    | Иконка/подпись/sort/slug навигации, интервал poll, лимит сообщений      |
| `notifications.*`                         | вкл., звук, browser, poll 15s                                                 | Уведомления о новых сообщениях + HTTP-poll фолбэк к Echo                |

## Архитектура

- `Services\MaxMessageService` — история сообщений (`storeIncoming`, `storeOutgoing`, `conversations`, `markRead`);
- `Services\MaxChatSender` — отправка ответов (`sendFormatted`, `sendAttachment`) через ApiClient;
- `Services\MaxAttachmentStore` — приватное хранение вложений (метаданные в JSON-колонке);
- `Support\TextSanitizer` — санитизация HTML под whitelist тегов MAX (TextFormat: html);
- `Events\MaxMessageCreated` — ShouldBroadcast в private-канал;
- `Livewire\OperatorChat` (алиас `filament-max-chat`) + `Pages\OperatorChat`.

Модель `Models\MaxChat` расширяет `GeekCo\LaravelMaxClient\Models\MaxChat` связями
`messages`/`lastMessage`/`maxUser` и работает с той же таблицей `max_chats` — реестр чатов клиента (`chats.enabled` /
`MAX_CHATS_ENABLED`) продолжает работать без изменений.

Кнопка «Очистить историю» удаляет только сообщения диалога (через `MaxMessageService::clearHistory`). Отдельное действие
«Удалить чат» (`MaxMessageService::removeChat`) помечает запись реестра статусом `removed` — диалог исчезает из списка
чатов оператора (список фильтрует только `Active`), при этом история сообщений сохраняется.

Если пользователь снова напишет в удалённый чат, `MaxMessageService::storeIncoming` (через `upsertChat`) вернёт статус
записи обратно в `active` — диалог снова появится в списке оператора с сохранённой историей. Это осознанное поведение:
оператор не пропускает реальные обращения, а «удаление» действует как уборка диалога из списка до следующего сообщения.

## Открытие конкретного чата по ссылке

На страницу чата можно вести прямую ссылку на конкретный диалог с внешних страниц — по идентификатору чата MAX
(`chat_id`) либо по внутреннему ID записи `max_chats`:

- `/admin/chat?chat_id=<id чата в MAX>` — плагин сам найдёт запись реестра по `chat_id` и откроет диалог;
- `/admin/chat?chat=<id max_chats>` — обратная совместимость, открытие по внутреннему ID записи.

Пример сформировать такую ссылку из вашего кода:

```php
route(OperatorChat::getRouteName(), ['chat_id' => $chat->chat_id])
```

Если `chat_id` не найден в реестре `max_chats`, страница откроется как обычно (без активного диалога) — это безопасно
при прямом переходе по ссылке.

## Обновление с v1.x (миграция `max_bot_chats` → `max_chats`)

laravel-max-client v1.1.0 переименовал реестр чатов: модель `BotChat` → `MaxChat`, таблица `max_bot_chats` →
`max_chats`, enum `BotChatStatus` → `MaxChatStatus`. Плагин использует новую модель `Models\MaxChat` (config-ключ
`chat_model`) и FK `max_chat_id` на таблицу `max_chats`. В `max_chat_messages` внешний ключ переименован с
`bot_chat_id` на `max_chat_id`, а в JSON-контрактах `latest_bot_chat_id` заменён на `latest_max_chat_id`.

При обновлении существующей установки перенесите данные реестра в новую таблицу вручную, а затем переименуйте колонку
внешнего ключа в таблице сообщений:

```sql
CREATE TABLE max_chats LIKE max_bot_chats;
INSERT INTO max_chats
SELECT *
FROM max_bot_chats;
DROP TABLE max_bot_chats;

ALTER TABLE max_chat_messages RENAME COLUMN bot_chat_id TO max_chat_id;
```

Альтернативно — пересоздайте реестр с чистого листа: включите `MAX_CHATS_ENABLED` и переподпишитесь на
`bot_added`/`bot_started`, чтобы пакет заново наполнил `max_chats`.

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
