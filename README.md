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
- `geekcodev/laravel-max-client` ^1.2.0 + `geekcodev/max-php-client` ^1.1.8. Constraint в `composer.json` — `^1.2.0`:
  версия `1.2.0` обязательна не по привычке — на ней реестр перешёл на новую форму (одна строка на чат, PK `chat_id`,
  связи в `max_chat_users`). Фактически установленную версию всегда сверяй `composer show geekcodev/laravel-max-client`.
- Миграции laravel-max-client подгружаются автоматически, публиковать их не нужно. Плагин занимает **полосу**
  `0000_02`: `max_chat_messages.max_chat_id` ссылается на `max_chats` из `laravel-max-client` (`0000_00`), а
  `max_chat_messages.operator_id` — на `users` из приложения (`0000_01`), поэтому обе таблицы обязаны применяться
  раньше. Полоса в имени миграции — это позиция пакета в общем порядке миграций приложения и всех пакетов, который
  Laravel строит по именам файлов. Требование «опубликованные миграции» было верно только до v1.1.1 и больше не
  действует. Полосы несамостоятельны: `0000_02` плагина работает только с адаптером, чьи миграции лежат в `0000_00`,
  поэтому обновляйте `laravel-max-client` до версии с полосой `0000_00` (v1.2.0 ещё использует `0001_01_01_*`, и с ней
  чистая установка снова падает на поздней `max_chats`).
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

Правки и удаления сообщений в MAX тоже приходят апдейтами, и по умолчанию плагин их игнорирует: локальная история
разойдётся с MAX, и в ленте останется старая редакция или удалённое сообщение. Если нужна синхронная история,
обработайте и их:

```php
match ($event->update->updateType) {
    UpdateType::MessageCreated => $this->chatMessages->storeIncoming($event->update),
    UpdateType::MessageEdited => $this->chatMessages->applyIncomingEdit($event->update),
    UpdateType::MessageRemoved => $this->chatMessages->applyIncomingRemoval($event->update),
    default => null,
};
```

- `applyIncomingEdit()` переписывает текст уже сохранённого сообщения по `message_id`; неизвестное сообщение не
  создаётся, а апдейт без нового текста ничего не трогает, чтобы пустое событие не вытерло переписку;
- `applyIncomingRemoval()` удаляет строку истории и файл вложения с приватного диска, приводит её в соответствие с MAX;
- оба метода ищут сообщение по паре «`chat_id` + `message_id`», поэтому событие из другого чата не тронет вашу историю;
- обновление видно в открытом чате при следующем опросе ленты (`ui.poll_interval`): отдельных broadcast-событий на
  правку и удаление нет, Echo-подписчик умеет только дописывать новые сообщения;
- `dialog_cleared` (очистка диалога в MAX) локальную историю не трогает — это необратимая операция, решение о ней
  остаётся за приложением.

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

- `Services\MaxMessageService` — история сообщений (`storeIncoming`, `storeOutgoing`, `applyIncomingEdit`,
  `applyIncomingRemoval`, `conversations`, `markRead`);
- `Console\MaxChatUpgradeCommand` (`max-chat:upgrade`) — перевод истории переписки на форму реестра чатов v1.2.0;
- `Services\MaxChatSender` — отправка ответов (`sendFormatted`, `sendAttachment`) через ApiClient;
- `Services\MaxAttachmentStore` — приватное хранение вложений (метаданные в JSON-колонке);
- `Support\TextSanitizer` — санитизация HTML под whitelist тегов MAX (TextFormat: html);
- `Events\MaxMessageCreated` — ShouldBroadcast в private-канал;
- `Livewire\OperatorChat` (алиас `filament-max-chat`) + `Pages\OperatorChat`.

Модель `Models\MaxChat` расширяет `GeekCo\LaravelMaxClient\Models\MaxChat` связями
`messages`/`lastMessage` и работает с той же таблицей `max_chats` — реестр чатов клиента (`chats.enabled` /
`MAX_CHATS_ENABLED`) продолжает работать без изменений.

С laravel-max-client v1.2.0 в `max_chats` одна строка на чат, первичный ключ — сам `chat_id`, а пользователи чата лежат
в `max_chat_users`. Поэтому в `max_chat_messages.max_chat_id` хранится `chat_id` чата в MAX, а собеседник оператора
берётся через `interlocutor()` (первый пользователь чата, который не бот). Связи `maxUser()` и колонки
`max_chats.user_id` больше нет. Название для интерфейса даёт `displayName()` — у группы и канала это `title` из
`getChat()`, у диалога имя собеседника.

`MaxMessageService` сам заводит связь «чат — пользователь» в `max_chat_users` при сохранении сообщения: ответы оператора
и `storeIncomingForUser()` приходят без апдейта MAX, где эту связь обычно регистрирует слушатель пакета.

Если бота только что добавили в группу или канал, участники которых появились в MAX раньше плагина, связей в
`max_chat_users` ещё нет и собеседника (`interlocutor()`) не существует. На отправку ответов это не влияет: `POST
/messages` принимает `chat_id` без `user_id`, поэтому адресатом ответа оператора становится сам чат. Локальная история
такие сообщения тоже сохраняет — `max_chat_messages.user_id` nullable, а в ленте вместо имени показывается «Канал»,
«Группа» или «Без отправителя» по типу чата. Счётчики непрочитанного и отметка прочтения от `user_id` не зависят.

Часть таких чатов находится поиском только по тексту сообщений: искать по имени нечего, пока состав не синхронизирован.
Состав чата синхронизирует адаптер — в текущей версии он берёт участников только из апдейтов, которые несут
`user`, поэтому у канала и группы без собственных сообщений список участников остаётся неполным. План доработки
адаптера: `.agents/plans/2026-10-02-laravel-max-client-chat-member-sync.md` (файл не попадает в архив пакета).

Кнопка «Очистить историю» удаляет только сообщения диалога (через `MaxMessageService::clearHistory`). Отдельное действие
«Удалить чат» (`MaxMessageService::removeChat`) помечает запись реестра статусом `removed` — диалог исчезает из списка
чатов оператора (список фильтрует только `Active`), при этом история сообщений сохраняется.

Если пользователь снова напишет в удалённый чат, `MaxMessageService::storeIncoming` (через `upsertChat`) вернёт статус
записи обратно в `active` — диалог снова появится в списке оператора с сохранённой историей. Это осознанное поведение:
оператор не пропускает реальные обращения, а «удаление» действует как уборка диалога из списка до следующего сообщения.

## Открытие конкретного чата по ссылке

На страницу чата можно вести прямую ссылку на конкретный диалог с внешних страниц — по идентификатору чата в MAX:

- `/admin/chat?chat_id=<id чата в MAX>` — плагин найдёт запись реестра по `chat_id` и откроет диалог;
- `/admin/chat?chat=<id чата в MAX>` — алиас того же параметра для обратной совместимости.

С версии 1.1.0 внутреннего ID записи реестра нет: первичный ключ `max_chats` — сам `chat_id`, поэтому оба параметра
значат одно и то же.

Пример сформировать такую ссылку из вашего кода:

```php
route(OperatorChat::getRouteName(), ['chat_id' => $chat->chat_id])
```

Если `chat_id` не найден в реестре `max_chats`, страница откроется как обычно (без активного диалога) — это безопасно
при прямом переходе по ссылке.

## Обновление с v1.0.x на v1.1.0 (новая схема реестра чатов)

laravel-max-client v1.2.0 перестроил реестр чатов: вместо строки на пару «пользователь + чат» в `max_chats` стало одна
строка на чат с первичным ключом `chat_id`, а связи с пользователями вынесены в `max_chat_users`. Пересборку таблицы
делает команда адаптера `php artisan max:upgrade`.

```bash
composer require geekcodev/filament-max-chat:^1.1.0
php artisan migrate          # max_chat_users миграциями адаптера + nullable user_id миграцией плагина
php artisan max-chat:upgrade # переносит max_chat_id на chat_id, пересобирает реестр и возвращает FK
```

`php artisan migrate` создаёт `max_chat_messages` и аддитивную миграцию, снимающую `NOT NULL` с
`max_chat_messages.user_id`. Вторая нужна для каналов и групп, у которых на момент установки ещё нет ни одной связи в
`max_chat_users` (см. «Схема реестра v1.2.0»): собеседника в реестре нет, а колонка пустая. Она обратная — откат
подставляет `chat_id` вместо `null`, чтобы не падать на данных. На перевод истории она не влияет:
`max-chat:upgrade` работает с `max_chat_id`.

Команда `max-chat:upgrade` сама зовёт `max:upgrade` адаптера между своими двумя фазами, поэтому запускать адаптер
отдельно не нужно и нельзя: пока на `max_chats` висит FK из `max_chat_messages`, `max:upgrade` упадёт на пересборке
таблицы.

Порядок обязателен и продиктован ограничениями СУБД:

- пока на `max_chats` висит FK из `max_chat_messages` со суррогатной `id`, пересборка таблицы падает с ошибкой
  `cannot drop table max_chats because other objects depend on it` — поэтому FK снимается самой командой до вызова
  `max:upgrade` (вместе с переносом значений и расширением колонок);
- перенести значения надо до пересборки: соответствие «старый `id` → `chat_id`» после неё исчезает вместе с колонкой
  `id`;
- вернуть FK на `max_chats.chat_id` можно только после пересборки, когда эта колонка станет первичным ключом, — поэтому
  это вторая фаза, то есть шаг после `max:upgrade`.

Значения переносятся через временную таблицу соответствия одним запросом, поэтому перевод работает и на MySQL, и на
PostgreSQL, и на SQLite. На чистой установке обе фазы ничего не делают. Отдельной миграции перевода у плагина нет
намеренно: `php artisan migrate` проходит целиком до `max:upgrade`, поэтому перенос значений в миграции означал бы, что
без `max-chat:upgrade` таблица осталась бы наполовину переведённой. Сейчас без команды схема остаётся целиком старой, а
перевод выполняется целиком и возобновляемо. Сам перевод необратим: после `max:upgrade` колонки
`max_chats.id` больше нет, поэтому откатывать плагин поверх нового адаптера нельзя. Если `max:upgrade` упал, команду
достаточно повторить — обе фазы идемпотентны.

Если своя модель чата или свои запросы к `max_chats`, проверьте их после обновления: колонки `max_chats.id` и
`max_chats.user_id` больше нет, а `max_chat_messages.max_chat_id` содержит `chat_id` чата в MAX.

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
docker compose exec -T app composer test           # PHPUnit (SQLite in-memory)
docker compose exec -T app composer analyse        # PHPStan level max (Larastan)
docker compose exec -T app composer lint           # PHP-CS-Fixer (--dry-run)
docker compose exec -T app composer format         # PHP-CS-Fixer (исправить)
docker compose exec -T app composer coverage        # PHPUnit + гейт покрытия ≥95% строк
docker compose exec -T app composer security-audit # composer audit
```

Флаг `-T` у `docker compose exec` обязателен для неинтерактивных запусков: без него вывод PHPUnit/PHPStan ломается при
перенаправлении. Xdebug в контейнере включён, для покрытия режим задаётся явно:

```bash
XDEBUG_MODE=coverage docker compose run --rm -e XDEBUG_MODE=coverage app composer coverage
```

Для профилирования/отладки подключайтесь к `host.docker.internal:9003`.

## История изменений

Значимые пункты дублируются здесь из `.agents/release/RELEASE_NOTES_vX.Y.Z.md`; полные release notes лежат в
`.agents/release/`.

| Версия        | Дата                  | Итог                                                                                                                                                                                                                                                                                                  |
|---------------|-----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| v1.1.0        | 2026-09-04            | Совместимость с `laravel-max-client` 1.2: адресация истории по `chat_id`, `MaxChat::interlocutor()`/`displayName()`, поиск по всем пользователям чата, хранение сообщений чатов без собеседника (`user_id` nullable). Ломающий переход (нужны `php artisan migrate` и `php artisan max-chat:upgrade`) |
| v1.0.9        | 2026-09-03            | Несколько вложений с предпросмотром и вставкой ссылки, `storeIncomingForUser` для действий пользователя без апдейта MAX                                                                                                                                                                               |
| v1.0.0–v1.0.8 | 2026-08-25…2026-09-01 | Первая реализация плагина: страница чата, лента, ответы и вложения, real-time доставка и глобальный счётчик непрочитанного, аватары и карточка чата                                                                                                                                                   |
