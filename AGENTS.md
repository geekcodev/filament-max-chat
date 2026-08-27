# AGENTS.md

> Проектный контекст и рабочие правила для разработчиков и ИИ-агентов (включая opencode).
> Читай этот файл **целиком** в начале работы — он задаёт архитектуру, обязательный процесс проверок (Gate)
> и требования SOLID / DRY / KISS / OWASP Top 10.
> Пользовательскую документацию (установка, быстрый старт, интеграция) — в `README.md`.

## 1. О проекте

- **Что это.** Filament-плагин **`geekcodev/filament-max-chat`** — **чат оператора** с пользователями MAX-мессенджера
  внутри Filament-панели. Строится поверх `geekcodev/laravel-max-client` (реестр чатов `max_bot_chats`/`max_users`,
  вебхук-доставка апдейтов) и ядра `geekcodev/max-php-client` (Bot API MAX). Репозиторий/рабочая папка —
  `filament-max-chat`.
- **Принцип.** Плагин — только UI и история переписки: страница `/admin/chat`, Livewire-компонент, хранение
  `chat_messages`, приватное хранение вложений, broadcast-событие. Бизнес-обработка входящих апдейтов MAX остаётся в
  host-приложении — оно вызывает `ChatMessageService::storeIncoming()`. Не дублировать механизмы laravel-max-client.
- **Лицензия.** MIT (файл `LICENSE`).
- **Язык.** Рабочий язык общения с пользователем — **русский**; подписи UI — через lang-файлы (`lang/ru`, `lang/en`).

## 2. Ветки и состояние git

- `main` — стабильная, соответствует релизам; релиз — тег `vX.Y.Z`.
- `version` в `composer.json` **не указывается** — версия берётся из git-тегов.
- `.env` — untracked (в `.gitignore`). **Никогда не коммитить секреты** (`MAX_API_TOKEN`,
  `MAX_WEBHOOK_SECRET`). Коммиты и push делает пользователь — без явного запроса не коммить.

## 3. Правила для ИИ-агентов

1. В начале работы прочитай `AGENTS.md` и `README.md`.
2. **Не коммить и не пушить без явного запроса пользователя.**
3. Перед завершением любой задачи, менявшей код, прогони обязательный Gate (раздел 7) целиком. Результаты не подменяй;
   недоступный шаг честно указывай в отчёте, а не пропускай молча.
4. Не выдумывай сигнатуры MAX API: источник истины — `GeekCo\MaxPhpClient\ApiClient` и спецификация
   `https://github.com/geekcodev/max-openapi`. Отправка сообщений — только через пакетные сервисы (`MaxChatSender`).
5. Если для задачи чего-то не хватает (токен, сеть, контейнер) — скажи об этом, а не упрощай задачу молча.
6. Ответы — краткие и по делу; в коде — без лишних комментариев.
7. Текст в Markdown-файлах (AGENTS.md, README.md, RELEASE, PLAN и др.) пиши как человек:
   связный текст, абзацы, а не сплошные списки из буллетов. Списки — только когда действительно перечисляешь однородные
   пункты. Без «Возможности:» с 15 тире подряд.

## 4. Структура репозитория

```
config/filament-max-chat.php       publishable-конфиг (--tag=filament-max-chat-config)
database/migrations/               миграция chat_messages (грузится автоматически из пакета)
lang/{ru,en}/chat.php              подписи UI страницы чата
resources/views/pages/             Filament-страница OperatorChat
resources/views/components/        Blade-компонент Livewire-чата
src/
  FilamentMaxChatServiceProvider.php  composition root: config/views/lang publish, алиас компонента, роут вложений
  FilamentMaxChatPlugin.php           Filament v5 plugin: страница чата в панели
  Pages/OperatorChat.php              страница панели (доступ permissions.view)
  Livewire/OperatorChat.php           состояние чата: диалоги, лента, ответ, вложения (алиас filament-max-chat)
  Services/
    ChatMessageService.php            история: storeIncoming/storeOutgoing/conversations/messagesFor/markRead
    MaxChatSender.php                 отправка в MAX: sendFormatted (HTML) / sendAttachment (uploadMedia)
    ChatAttachmentStore.php           приватное хранение вложений (метаданные в JSON-колонке attachment)
  Support/TextSanitizer.php           санитизация HTML под whitelist тегов MAX + toMaxHtml()
  Models/BotChat.php                  расширение пакетной модели клиента (связи messages/lastMessage/maxUser)
  Models/ChatMessage.php              модель chat_messages
  Events/ChatMessageCreated.php       ShouldBroadcast в private-канал chat.channel
  Enums/{ChatMessageDirection,ChatMessageSender}.php
  Http/Controllers/ChatAttachmentController.php  авторизованная отдача вложений
  Http/Controllers/UnreadCountController.php     JSON-счётчик непрочитанного (HTTP-poll уведомлений на всех страницах)
tests/                             PHPUnit + Orchestra Testbench
  Fixtures/                           AdminPanelProvider, TestUser, миграция users, Gate chat.view/chat.answer
  Unit/                               TextSanitizer, ChatAttachmentStore, ChatMessageService
  Feature/                            Livewire OperatorChat, ChatAttachmentController
Dockerfile                         PHP 8.4 (ghcr.io/geekcodev/php) + опциональный Xdebug
docker-compose.yml                 сервис app, user 1000:1000, volume ./
docker/config/usr/local/etc/php/conf.d/40-custom.ini  PHP-конфиг dev-контейнера (memory_limit=1G)
composer.json                      PSR-4 GeekCo\FilamentMaxChat\, PHP ^8.4
phpunit.xml                        failOnRisky/failOnWarning; SQLite in-memory
phpstan.neon                       level max (Larastan), configDirectories → config/
.php-cs-fixer.dist.php             PSR-12 + declare_strict_types + no_unused_imports
.env.example                       эталон имён переменных (FILAMENT_MAX_CHAT_*)
```

`composer.lock`, `.phpunit.cache/`, `vendor/` — в `.gitignore`.

## 5. Архитектура и ключевые контракты

- **Подключение**: `->plugin(FilamentMaxChatPlugin::make())` в PanelProvider. Доступ к странице — право
  `permissions.view` (`chat.view`), отправка ответов — `permissions.answer` (`chat.answer`); права проверяются строкой
  `$user->can(...)` — совместимо со spatie/laravel-permission и Gate.
- **Входящие сообщения**: host-приложение слушает `MaxUpdateReceived` (laravel-max-client) и вызывает
  `ChatMessageService::storeIncoming(Update)` — создаёт `chat_messages`, обновляет имя диалога, скачивает медиа во
  приватный диск, эмитит `ChatMessageCreated`.
- **Исходящие**: `MaxChatSender` — единственная точка отправки (`sendFormatted` с `format=html` после
  `TextSanitizer`; `sendAttachment` — `uploadMedia` + `sendFile`). Прямые вызовы ApiClient из Livewire запрещены.
- **Вложения**: метаданные (type/path/name/mime/size) — JSON-колонка `attachment`; файлы на диске `attachments.disk`
  вне public; отдача только через `GET route.uri` с правом `permissions.view` (`ChatAttachmentController`).
- **Real-time**: `ChatMessageCreated` (broadcastAs `chat-message.created`) в private-канал `broadcast_channel`;
  клиентская часть — `window.Echo`, фолбэк — `wire:poll` (интервал `ui.poll_seconds`). Глобальный счётчик
  непрочитанного на всех страницах панели: Echo + HTTP-poll (`GET route.unread_count_uri` →
  `UnreadCountController`, JSON `{unread_count, latest_bot_chat_id}`, интервал `notifications.poll_interval_seconds`).
- **Переопределение моделей**: `bot_chat_model` — подкласс пакетного `Models\BotChat` (та же таблица `max_bot_chats`);
  `user_model` — модель оператора для связи `operator_id`.
- **Миграция** `0001_01_01_000001_create_chat_messages_table.php` грузится автоматически из пакета; FK на
  `max_bot_chats` требует опубликованных миграций laravel-max-client (см. README «Требования»).

### Соглашения

- PHP **8.4**, `declare(strict_types=1)` во всех файлах, PSR-12, PHPStan **level max** (Larastan).
- Namespace `GeekCo\FilamentMaxChat` (тесты `GeekCo\FilamentMaxChat\Tests`), PSR-4.
- SOLID / DRY / KISS: тонкий Livewire-компонент (состояние), логика — в сервисах; без дублирования laravel-max-client.
- Не добавлять комментарии без необходимости. Имена — английские; русские тексты — в lang-файлах и тестах.
- Тесты обязательны для нового кода: unit — сервисы/sanitizer; feature — Livewire и HTTP через Testbench + фикстуры
  (`tests/Fixtures`: панель, TestUser, Gate). Моки `MaxChatSender` — Mockery через `$this->mock()`.

## 6. Локальная разработка

PHP/Composer на хосте не требуются — всё через Docker:

```bash
docker compose up -d --build
docker compose run --rm app composer install
docker compose exec app composer test      # PHPUnit
docker compose exec app composer analyse   # PHPStan level max
docker compose exec app composer lint      # php-cs-fixer --dry-run
docker compose exec app composer format    # php-cs-fixer fix
docker compose exec app composer audit     # composer audit
```

## 7. Обязательный Gate перед завершением задачи

1. **Lint PHP**: `composer lint` (php-cs-fixer --dry-run) → 0 ошибок; при правках — `composer format`.
2. **Статика**: `composer analyse` (PHPStan level max) → 0 ошибок.
3. **Тесты**: `composer test` (PHPUnit) → зелёные (failOnRisky/failOnWarning).
4. **Audit**: `composer audit` → 0 критичных.

Все шаги обязательны. Недоступный шаг — честно в отчёт.

## 8. OWASP Top 10 (обязательно при написании кода)

- **A01** — доступ к странице/роуту вложений и счётчика непрочитанного только по правам (`permissions.view`);
  fail-closed, JSON 401/403 для неавторизованного/без прав.
- **A02** — секреты только в env; вложения — вне public-корня.
- **A03** — HTML операторов санитизируется `TextSanitizer` (whitelist тегов MAX) до сохранения и до отправки;
  Blade-экранирование по умолчанию.
- **A04** — лимиты загрузки (`attachments.upload_max_kb`, whitelist `attachments.mimes`, жёсткий `max_bytes` при
  скачивании); входящие URL медиа — только домены CDN MAX.
- **A05** — publishable-конфиг с безопасными дефолтами; `route.enabled=false` отключает роут полностью.
- **A06** — `composer audit` в Gate; lock-файлы актуальны.
- **A07** — гостю на роут вложений — редирект на `route.login_url`; постоянновременные сравнения — зона ответственности
  laravel-max-client.
- **A09** — ошибки отправки в MAX логируются без чувствительных данных; исключения не глушатся молча.
