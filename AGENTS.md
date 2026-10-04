# AGENTS.md

> Проектный контекст и рабочие правила для разработчиков и ИИ-агентов (включая opencode).
> Читай этот файл **целиком** в начале работы — он задаёт архитектуру, обязательный процесс проверок (Gate)
> и требования SOLID / DRY / KISS / OWASP Top 10.
> Пользовательскую документацию (установка, быстрый старт, интеграция) — в `README.md`.

## 1. О проекте

- **Что это.** Filament-плагин **`geekcodev/filament-max-chat`** — **чат оператора** с пользователями MAX-мессенджера
  внутри Filament-панели. Строится поверх `geekcodev/laravel-max-client` (реестр чатов `max_chats`/`max_users`/
  `max_chat_users`, вебхук-доставка апдейтов) и ядра `geekcodev/max-php-client` (Bot API MAX). Репозиторий/рабочая
  папка — `filament-max-chat`, переиспользуемый автономный пакет.
- **Что даёт.** Страница `/admin/chat` в панели: список диалогов с непрочитанными счётчиками, лента сообщений с
  HTML-разметкой и вложениями, ответ оператора текстом или файлом, real-time доставка новых сообщений и глобальный
  счётчик непрочитанного на всех страницах панели. Своя таблица одна — `max_chat_messages`; реестр чатов и пользователей
  принадлежит `laravel-max-client`.
- **Принцип.** Плагин — **тонкий слой UI и истории переписки**: страница, Livewire-компонент, `max_chat_messages`,
  приватное хранение вложений, broadcast-событие. Механизмы `laravel-max-client` не дублируются: отправка — только через
  `MaxChatSender`, профиль — только через `MaxUserProfileService`, доставка апдейтов — только через `MaxUpdateReceived`.
  Бизнес-обработка входящих апдейтов MAX остаётся в host-приложении (раздел 5).
- **Лицензия.** MIT (файл `LICENSE`).
- **Язык.** Рабочий язык общения с пользователем и всех md-файлов — **русский**; подписи UI — через lang-файлы
  (`lang/ru`, `lang/en`).

### Статус и версии

Актуальную версию **всегда сверяй по источникам, а не по цифрам в этом файле**: constraints — в `composer.json` и
`README.md` («Требования»), реально установленная — `composer show geekcodev/laravel-max-client` и
`composer show geekcodev/max-php-client` (по одному пакету за раз: два аргумента `composer show` не принимает), релизные
теги — `git tag --sort=-v:refname` здесь и в соседних `../laravel-max-client`, `../max-php-client`.

Constraint `geekcodev/laravel-max-client: ^1.2.0` в `composer.json` — намеренно жёсткий: код плагина, миграции и тесты
рассчитаны на форму реестра 1.2 (`max_chats.chat_id` — первичный ключ, связи в `max_chat_users`, §5 «Схема v1.2.0
реестра»), а несовместимость с 1.1.x — ломающий переход, а не случайность. Но `composer.lock` не коммитится, поэтому
фактически установленную версию всегда сверяй по `composer show`, а не по constraint в файле: расхождение даёт красный
Gate (PHPStan, тесты). Release notes перехода — `.agents/release/RELEASE_NOTES_v1.1.0.md`, порядок обновления
существующей установки — §5 и README «Обновление с v1.0.x на v1.1.0».

### Регрессии не чиним в стороннем проекте

Если расхождение или пробел обнаружен в интеграционном Laravel-приложении, использующем этот пакет, — это регрессия
плагина, а не особенность приложения. Заводи задачу здесь (тест + фикс + релиз) и не предлагай обход в стороннем
репозитории, если обход маскирует дефект самого пакета.

## 2. Ветки, git и релизы

- `dev` — рабочая ветка разработки; `main` — стабильная, соответствует релизам; feature-ветки — `feat/*` и сливаются в
  `dev`. Релиз — тег `vX.Y.Z`.
- `version` в `composer.json` **не указывается** — версия берётся из git-тегов.
- `.env`, `vendor/`, `composer.lock`, `.phpunit.cache/`, `.phpstan-cache/`, `build/`, `coverage/`,
  `.php-cs-fixer.cache`,
  `*.log` — untracked (в `.gitignore`). **Никогда не коммитить секреты** (`MAX_API_TOKEN`, `MAX_WEBHOOK_SECRET`).
- Коммиты и push делает пользователь. **Не коммить и не пушить без явного запроса.**
- **Различай «текст коммита» и «коммит».** «Напиши текст коммита» — верни только текст, коммит не создавай.
  «Закоммить» — создай коммит. Не смешивай эти запросы.
- **«Напиши краткий текст коммита» — это только head, без body.** Возвращай одну строку: subject на английском по
  Conventional Commits, собранный по всем изменениям ветки (не по последнему действию). Body в такой ответ не пиши, даже
  если изменения большие: краткий текст = head. Полный текст с body пользователь может попросить отдельно («полный текст
  коммита», «с body»).
- **Текст коммита — на английском, по Conventional Commits, по всем изменениям ветки.** Subject (head) составляется из
  реального diff (`git status --short`, `git diff`, `git log`), а не из последнего действия: `feat:`, `fix:`, `docs:`,
  `chore:`, `refactor:`, `test:` — с маленькой буквы, без точки в конце, до 72 символов. Он должен кратко отражать
  **все** изменения ветки целиком, а не только самую заметную часть. Body — только когда его просят: с переносами на 100
  символов, с описанием «что изменилось и почему», без пересказа кода. Ломающий переход или миграция данных — либо
  `feat!:` с `BREAKING CHANGE:`, либо minor-релиз.
- Перед коммитом обязательно `git status --short` и `git diff`: в индекс добавляй **явный список файлов**, а не
  `git add .`/`git add -A`. Перед релизом рабочее дерево должно быть чистым, кроме ожидаемых файлов релиза.
- **Релиз**: release-notes в `.agents/release/RELEASE_NOTES_vX.Y.Z.md` → merge `dev → main` →
  `git tag vX.Y.Z && git push origin vX.Y.Z` → GitHub Release из тега → Packagist (обновляется по webhook). Значимые
  пункты релиза продублировать в `README.md` (раздел «История изменений»).
- **Формат release-notes**: `Новое` / `Изменение (BC)` / `Затронутые сценарии` / `Качество` (тесты, покрытие, аудит).
  Файл не удаляется после релиза — история остаётся в `.agents/release/`. Ранее эти файлы лежали в корне как
  `RELEASE-vX.Y.Z.md` — история сохранена, новые создаются только в `.agents/release/`.

## 3. Правила для ИИ-агентов

1. В начале работы прочитай `AGENTS.md`, `README.md` и активный план `.agents/plans/PLAN-filament-max-chat.md` целиком.
   Примеры документированного workflow — соседние пакеты: `laravel-max-client` (`/home/user/web/laravel-max-client/`) и
   `filament-max-users` (`/home/user/web/filament-max-users/`); источник истины по API MAX — ядро
   `../max-php-client/docs/api-reference.md` (раздел 9).
2. **Не коммить и не пуши без явного запроса пользователя.**
3. Перед завершением любой задачи, менявшей код, прогони обязательный Gate (раздел 7) целиком и сверься с чек-листом
   (раздел 11). Результаты не подменяй; недоступный шаг честно указывай в отчёте, а не пропускай молча.
4. Не выдумывай сигнатуры MAX API: сверяйся с `GeekCo\MaxPhpClient\ApiClient`, `docs/api-reference.md` ядра и
   https://github.com/geekcodev/max-openapi. Отправка сообщений — только через `MaxChatSender`, профиль — только через
   `MaxUserProfileService`, вложения — только через `MaxAttachmentStore`. Прямые вызовы `ApiClient` из Livewire, страниц
   и контроллеров запрещены.
5. Если для задачи чего-то не хватает (токен, сеть, контейнер, драйвер покрытия) — скажи об этом, а не упрощай задачу
   молча.
6. Ответы — краткие и по делу; в коде — без лишних комментариев и без декоративных символов (никаких «ёлочек», эмодзи,
   псевдографики в сообщениях, именах и документации): только русский и английский языки.
7. Текст в Markdown-файлах (AGENTS.md, README.md, release-notes, планы, журнал) пиши как человек: связный текст, абзацы,
   а не сплошные списки из буллетов. Списки — только когда действительно перечисляешь однородные пункты (Gate, gotchas,
   чек-лист).
8. `.env.example` — единственный эталон имён переменных плагина; при добавлении новой `FILAMENT_MAX_CHAT_*` переменной
   синхронизируй его и `config/filament-max-chat.php`.
9. Любой ключ перевода, который ты добавил в код, обязан существовать и в `lang/ru`, и в `lang/en` — иначе UI покажет
   сырые ключи, а тесты этого не поймают.
10. По завершении каждой сессии заноси итог по формату из 4.1: строка в `.agents/journals/JOURNAL.md` + файл в
    `.agents/journals/sessions/`, отмечай выполненные пункты в `.agents/plans/PLAN-filament-max-chat.md`. Каталог
    `.agents/` **коммитится** — прогресс должен быть виден после `git clone`.
11. Перед правкой проверь чужие ловушки из раздела 10, а не открывай их заново.
12. Проверка кода в условиях CI обязательна, когда менялись зависимости или требования PHP: локальный `composer.lock`
    скрывает дрейф (gotcha 10) — локально зелёный код может быть красным в CI и наоборот.

## 4. Структура репозитория

```
config/filament-max-chat.php       publishable-конфиг (--tag=filament-max-chat-config)
database/migrations/               миграции max_chat_messages: create + nullable user_id (loadMigrationsFrom, публикация опциональна)
lang/{ru,en}/chat.php              подписи UI страницы чата
resources/
  css/filament-max-chat.css        стили чата и бейджа непрочитанного (импорт в теме хоста)
  views/pages/operator-chat.blade.php   Filament-страница (обёртка Livewire-компонента)
  views/components/operator-chat.blade.php   Blade Livewire-чата (диалоги, лента, ответ, вложения, wire:poll)
  views/components/notification-script.blade.php  клиентский JS: Echo + HTTP-poll + звук/браузерные уведомления
src/
  FilamentMaxChatServiceProvider.php  composition root: config/views/lang publish, алиас компонента, роуты
  FilamentMaxChatPlugin.php           Filament v5 plugin: страница чата в панели
  Pages/OperatorChat.php              страница панели (доступ permissions.view)
  Livewire/OperatorChat.php           состояние чата: диалоги, лента, ответ, вложения (алиас filament-max-chat)
  Console/MaxChatUpgradeCommand.php перевод истории переписки на форму реестра v1.2.0 (max-chat:upgrade)
  Services/
    MaxMessageService.php            история: storeIncoming/storeIncomingForUser/storeOutgoing/applyIncomingEdit/
                                     applyIncomingRemoval/conversations/messagesFor/markRead/deleteMessage/
                                     clearHistory
    ChatMessagesSchemaRepoint.php    две фазы перевода max_chat_messages: remap() и repin() (обе зовёт
                                     команда max-chat:upgrade, миграция плагина только create)
    MaxChatSender.php                 отправка в MAX: sendFormatted (HTML) / sendAttachment (uploadMedia + sendFile)
    MaxAttachmentStore.php            приватное хранение вложений (метаданные в JSON-колонке attachment)
    ChatProfileRefresher.php          троттлинг и диспатч фоновой подгрузки профиля (сам API не дёргает)
  Jobs/RefreshChatProfilesJob.php     фоновая подгрузка профиля через MaxUserProfileService (getChatMembers)
  Support/TextSanitizer.php           санитизация HTML под whitelist тегов MAX + toMaxHtml()
  Models/MaxChat.php                  расширение пакетной модели клиента (messages/lastMessage, interlocutor, displayName)
  Models/MaxMessage.php               модель max_chat_messages
  Events/MaxMessageCreated.php        ShouldBroadcast в private-канал chat.channel
  Enums/{MaxMessageDirection,MaxMessageSender}.php
  Http/Controllers/MaxAttachmentController.php  авторизованная отдача вложений
  Http/Controllers/UnreadCountController.php     JSON-счётчик непрочитанного (HTTP-poll на всех страницах панели)
tests/                             PHPUnit + Orchestra Testbench
  Fixtures/                          AdminPanelProvider, TestUser, миграция users, Gate chat.view/chat.answer
  Support/MakesChats.php             фикстуры реестра чатов под схему v1.2.0 (makeChat, linkChatUser, makeChatWithUser)
  Support/InspectsChatSchema.php     чтение внешних ключей таблицы для тестов команды перевода
  Unit/                              сервисы, sanitizer, модели, enums, job, страница, провайдер
  Feature/                           Livewire OperatorChat, MaxAttachmentController, UnreadCountController,
                                     MaxChatMessagesSchema, ChatMessagesSchemaRepoint, MaxChatUpgradeCommand,
                                     MigrationOrder (полоса миграций и порядок FK по исходникам)
scripts/check-coverage.php           проверка порога покрытия (≥95% строк) по build/coverage.xml
.agents/                            рабочая память проекта: plans/, release/, journals/{JOURNAL.md, sessions/} (см. 4.1)
.github/workflows/ci.yml             один job: lint → phpstan → phpunit + coverage gate → audit
Dockerfile                          PHP 8.4 (ghcr.io/geekcodev/php:8.4-bookworm) + опциональный Xdebug
docker-compose.yml                  сервис app, user 1000:1000, volume ./, XDEBUG_MODE=coverage
docker/config/usr/local/etc/php/conf.d/40-custom.ini  PHP-конфиг dev-контейнера (memory_limit=1G)
composer.json                       PSR-4 GeekCo\FilamentMaxChat\, PHP ^8.4
phpunit.xml                         failOnRisky/failOnWarning; SQLite in-memory; source → src/
phpstan.neon                        level max (Larastan), configDirectories → config/, tmpDir → .phpstan-cache
.php-cs-fixer.dist.php              PSR-12 + declare_strict_types + no_unused_imports (finder: src, tests, config,
                                    database, scripts)
.gitattributes                      export-ignore для .agents/, tests/, .github/ и dev-конфигов: чистый dist
.env.example                        эталон имён переменных (FILAMENT_MAX_CHAT_*)
```

`composer.lock`, `.phpunit.cache/`, `.phpstan-cache/`, `vendor/`, `build/` — в `.gitignore`; рабочая память `.agents/`
в git, но исключена из архива пакета через `.gitattributes`.

### 4.1. Прогресс, планы и release-notes

`.agents/` — единственное место рабочей памяти проекта: журнал, планы и release-notes лежат только здесь. Каталог
**коммитится** (осознанное отличие от `laravel-max-client`, где `.agents/` в `.gitignore`): смысл в том, чтобы следующая
сессия — в том числе на другой машине после `git clone` — продолжила с актуального места. Чтобы рабочая память не
попадала в публичный архив пакета, каталог перечислен в `.gitattributes` с `export-ignore` (то же для `tests/`,
`.github/` и dev-конфигов), а секретов в `.agents/` не пишется по правилам ниже. Если репозиторий когда-нибудь станет
приватным без зеркала — тогда `.agents/` можно убрать в `.gitignore`.

```
.agents/
  plans/                           многошаговые планы
  release/                         release-notes версий
  journals/
    JOURNAL.md                     таблица сессий, новые сверху
    sessions/                      подробности сессий
```

- `plans/PLAN-filament-max-chat.md` — активный план проекта: задачи, которые переживают одну сессию; шаги отмечаются в
  нём, дублировать в другие файлы не надо. Отдельные задачи оформляются как `plans/YYYY-MM-DD-<слаг>.md`.
- `release/RELEASE_NOTES_vX.Y.Z.md` — release-notes версий, по одной на версию, в своём формате; файлы не удаляются,
  история остаётся в git.
- `journals/JOURNAL.md` — таблица сессий, новые сверху, колонки `Дата`, `Файл`, `Теги`, `Описание`; в описании — что
  сделано и результат Gate. Над таблицей — заголовок и пояснение формата. Подробности — в файле сессии, здесь только
  указатель.
- `journals/sessions/YYYY-MM-DD-<слаг>.md` — тело сессии ≤5 КБ: YAML-frontmatter (`tags`, `date`), заголовок `# <тема>`
  без даты (дата — в имени файла и во frontmatter), затем секции `Проблема`, `Решение`, `Тесты`, `Нюансы`, `Gate`. Слаг
  латиницей в kebab-case: `2026-10-02-agents-workflow-and-gate.md`. Только факты и решения; пересказ кода и длинные логи
  не пишем.

### Что куда писать

| Вопрос                                   | Файл                                                                  |
|------------------------------------------|-----------------------------------------------------------------------|
| «Как устроен проект и что нельзя делать» | `AGENTS.md` — контракты, соглашения, Gate, gotchas, чек-лист          |
| «Что делаем сейчас и в каком порядке»    | `.agents/plans/PLAN-filament-max-chat.md` — шаги, критерии готовности |
| «Что произошло в конкретной сессии»      | `.agents/journals/sessions/*.md` + строка в `JOURNAL.md`              |
| «Что вошло в релиз X.Y.Z»                | `.agents/release/RELEASE_NOTES_vX.Y.Z.md`                             |
| «Как этим пользоваться» (для хоста)      | `README.md` — установка, конфигурация, интеграция, история            |

Правило: постоянное решение — в `AGENTS.md`; решение по конкретной задаче — в плане; факт о сессии — в журнале. Текст
правил в плане и журнале не дублируем, даём ссылку на раздел `AGENTS.md`.

Чего в `.agents/` **не** пишем: токены и секреты, payload и тела ответов MAX API, содержимое чужих репозиториев,
устаревшие рассуждения. Не выдумывай результаты проверок: недоступный шаг Gate пишется как недоступный.

## 5. Архитектура и ключевые контракты

- **Подключение**: `->plugin(FilamentMaxChatPlugin::make())` в PanelProvider. Доступ к странице — право
  `permissions.view` (`chat.view`), отправка ответов — `permissions.answer` (`chat.answer`); права проверяются строкой
  `$user->can(...)` — совместимо со spatie/laravel-permission и Gate. Оформление навигации и slug — `ui.*`
  (`navigation_group`, `navigation_icon`, `navigation_sort`, `navigation_label`, `title`, `slug`).
- **Входящие сообщения**: host-приложение слушает `MaxUpdateReceived` (laravel-max-client) и вызывает
  `MaxMessageService::storeIncoming(Update)` — создаёт `max_chat_messages`, обновляет имя диалога, скачивает медиа во
  приватный диск, эмитит `MaxMessageCreated`. Для действий пользователя без апдейта MAX (заявка, кнопка «Позвать
  оператора») есть `storeIncomingForUser(int $userId, int $chatId, ?User $user, ?string $text, ?string $messageId)`:
  профиль берётся из переданного `User` или резолвится из реестра `max_users`, сообщение сохраняется непрочитанным
  входящим, в MAX ничего не отправляется. Правка и удаление сообщений в MAX (`message_edited`, `message_removed`) —
  отдельные точки входа `applyIncomingEdit(Update)` и `applyIncomingRemoval(Update)`: обе ищут сообщение по паре «
  `chat_id` + `message_id`» (идентификаторы сообщений уникальны внутри чата, а не глобально), правка без нового текста и
  удаление с пустым `attachment` ничего не портят, удаление подчищает файл вложения через
  `MaxAttachmentStore::deleteStored()`. Broadcast-событий на правку и удаление нет — лента обновляется опросом.
- **Исходящие**: `MaxChatSender` — единственная точка отправки (`sendFormatted` с `format=html` после
  `TextSanitizer`; `sendAttachment` — `uploadMedia` + `sendFile`). Прямые вызовы `ApiClient` из Livewire запрещены.
- **Вложения**: метаданные (type/path/name/mime/size) — JSON-колонка `attachment`; файлы на диске `attachments.disk`
  вне public; отдача только через `GET route.uri` с правом `permissions.view` (`MaxAttachmentController`).
- **Real-time**: `MaxMessageCreated` (broadcastAs `chat-message.created`) в private-канал `broadcast_channel`;
  клиентская часть — `window.Echo` в `notification-script.blade.php`, фолбэк — `wire:poll` (интервал
  `ui.poll_interval`). Глобальный счётчик непрочитанного на всех страницах панели: Echo + HTTP-poll
  (`GET route.unread_count_uri` →
  `UnreadCountController`, JSON `{unread_count, latest_max_chat_id}`, интервал `notifications.poll_interval_seconds`).
  Echo подключается асинхронно: подписчик вешается на `EchoLoaded`, если `window.Echo` ещё не готов.
- **Профиль пользователя**: `ChatProfileRefresher` троттлит по `profile.cache_ttl` и диспатчит
  `RefreshChatProfilesJob` (`ShouldQueue`, `tries=3`, `timeout=60`), который дёргает `MaxUserProfileService`. Сам API из
  Livewire не вызывается — аватар появляется в `max_users` после фоновой задачи. `profile.prefetch`: `on_open`,
  `on_list`, `both`. В тестах очередь подменена (`Queue::fake()`), иначе при `QUEUE_CONNECTION=sync` задача уйдёт в
  реальный API.
- **Переопределение моделей**: `chat_model` — подкласс пакетного `GeekCo\LaravelMaxClient\Models\MaxChat` (таблица
  `max_chats`); `user_model` — модель оператора для связи `operator_id`. Модель связи чата и пользователя берётся из
  `laravel-max-client.chats.chat_users_model`.
- **Схема v1.2.0 реестра**: в `max_chats` одна строка на чат, первичный ключ — `chat_id`, колонок `id`/`user_id` нет;
  пользователи чата лежат в `max_chat_users`. Значит `max_chat_messages.max_chat_id` содержит `chat_id` чата в MAX, а
  собеседник оператора берётся через `MaxChat::interlocutor()` (первый не-бот в `chatUsers`). Отсутствие собеседника —
  штатная ситуация, а не ошибка: отправка в MAX идёт на `chat_id` (получатель `user_id` не обязателен), поэтому входящие
  с пустым sender, ответы оператора и поиск по таким чатам не должны теряться. Идентификаторы MAX бывают отрицательными
  (группы и каналы) — колонки идентификаторов **знаковые** `bigInteger`; тесты на SQLite такой случай не воспроизводят,
  поэтому знаковость проверяется по исходникам миграций (§10 gotcha 16). Идентификаторы **сообщений**, наоборот,
  строковые (`message_id` в MAX — строка) и уникальны только внутри чата, поэтому поиск локального сообщения по апдейту
  всегда идёт по паре «`chat_id` + `message_id» — иначе событие из одного чата перепишет историю другого.
- **Миграции** грузятся автоматически из пакета (`loadMigrationsFrom`, публикация не нужна). У плагина две
  миграции: `0000_02_000001_create_max_chat_messages_table.php` — create-миграция под форму реестра v1.2.0 с
  `user_id` **NOT NULL**, и `0000_02_000002_make_max_chat_messages_user_id_nullable.php` — аддитивная миграция,
  снимающая `NOT NULL`: у чата, чей состав в `max_chat_users` ещё не синхронизирован (канал, группа, куда бота только что
  добавили), собеседника нет. Она обратная (`down()` подставляет `chat_id` вместо `null`, чтобы откат не падал на
  данных) и сохраняет индексы при пересборке таблицы. Выполненные миграции нельзя править на месте — новая колонка
  или новое ограничение добавляются только отдельной аддитивной миграцией, поэтому правка create-миграции допустима
  лишь пока релиз не опубликован. Переименовывать миграцию можно только вместе с guard `Schema::hasTable()` в `up()`:
  новое имя числится невыполненным у установки, прошедшей старую версию, то есть переименование без guard ломает
  ровно ту установку, ради которой делается. Миграций, переписывающих данные, у плагина нет. Перенос истории
  на новую форму реестра миграцией **не** делается: `php artisan migrate` проходит целиком до `max:upgrade`, поэтому
  фаза remap выполняется внутри команды — иначе после `migrate` база осталась бы наполовину переведённой. Без команды
  схема остаётся целиком старой, перевод
  выполняется целиком и возобновляемо.
- **Порядок обновления существующей установки**: `php artisan migrate`, затем `php artisan max-chat:upgrade`
  (внутри зовёт `max:upgrade`). Отдельно `max:upgrade` не запускают: пока висит FK из `max_chat_messages`, адаптер не
  сможет пересобрать `max_chats`. Три ограничения СУБД по порядку: FK надо снять до пересборки `max_chats`, значения
  перенести до неё же (соответствие «старый id → chat_id» исчезает с колонкой `id`), а вернуть FK на
  `max_chats.chat_id` — только после (пока эта колонка не первичный ключ, ограничение не создаётся). Все три шага
  разнесены на две фазы `ChatMessagesSchemaRepoint` (remap до `max:upgrade`, repin после) и выполняются командой;

### Соглашения

| Принцип            | Применение в этом пакете                                                                                                                                                                                          |
|--------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| PHP 8.4 / strict   | `declare(strict_types=1)` во всех файлах, PSR-12, PHPStan **level max** (Larastan), namespace `GeekCo\FilamentMaxChat`                                                                                            |
| SOLID / DRY / KISS | Тонкий Livewire-компонент (только состояние), логика — в сервисах, отправка — только в `MaxChatSender`                                                                                                            |
| TDD                | Новый код покрыт тестами: unit — сервисы, sanitizer, модели, enums; feature — Livewire, контроллеры и права через Testbench + фикстуры                                                                            |
| Тестовые границы   | `MaxChatSender` мокается через `$this->mock()` (не `final`); `final`-сервисы `laravel-max-client` мокать нельзя — подменяй транспорт `ClientInterface`                                                            |
| BC-совместимость   | В patch-релизе не меняются публичные сигнатуры, ключи config, имена lang-строк и значения по умолчанию config; ломающие изменения — только в minor/major с записью в release-notes. Тексты подписей править можно |
| Production-grade   | Fail-closed права, безопасные дефолты конфига, никаких секретов в коде/логах, ошибки MAX видны пользователю и логируются                                                                                          |
| Локализация        | Имена — английские; русские тексты — в `lang/ru` и тестах; enum-подписи через `->label()`, без хардкода в представлениях                                                                                          |

## 6. Локальная разработка

PHP/Composer на хосте не требуются — всё через Docker:

```bash
docker compose up -d --build
docker compose run --rm app composer install
docker compose exec -T app composer lint           # php-cs-fixer --dry-run
docker compose exec -T app composer format         # php-cs-fixer fix
docker compose exec -T app composer analyse        # PHPStan level max
docker compose exec -T app composer test           # PHPUnit
docker compose exec -T app composer coverage        # PHPUnit + coverage gate ≥95%
docker compose exec -T app composer security-audit # composer audit
```

Флаг `-T` у `docker compose exec` обязателен для неинтерактивных команд: без него вывод PHPUnit/PHPStan ломается при
перенаправлении и портит разбор результатов. Для разовой оболочки — `docker compose run --rm app bash`. Покрытие требует
драйвера: в dev-контейнере Xdebug включён (`INSTALL_XDEBUG=true`, `XDEBUG_MODE=coverage`), в CI — `coverage: xdebug`.

## 7. Обязательный Gate перед завершением задачи

После изменений в `src/`, `tests/`, `config/`, `scripts/`, `.github/`, `database/`:

1. **Lint PHP**: `composer lint` (php-cs-fixer --dry-run) → 0 файлов с правками.
2. Если есть правки — `composer format`, затем повторить lint.
3. **Статика**: `composer analyse` (PHPStan level max) → 0 ошибок.
4. **Тесты**: `composer test` (PHPUnit) → зелёные (failOnRisky/failOnWarning).
5. **Покрытие**: `composer coverage` → ≥95% строк (`scripts/check-coverage.php`).
6. **Audit**: `composer security-audit` → 0 уязвимостей.
7. **Имитация CI на чистом резолве** (обязательно, §10 gotcha 14): копия дерева без `vendor/` и `composer.lock`,
   затем `composer install` и весь Gate заново внутри копии. Локальный `vendor/` в проверке не участвует — именно из-за
   него дрейф версий зависимостей скрывает красный CI (проверено на релизе v1.1.0: локально Larastan 3.11.0 был зелёным, а на
   свежем резолве 3.12.2 `View::make()` перестал принимать литерал имени вида как `view-string`).

Шаги 1–6 гоняются в dev-контейнере на текущем `vendor/` — это быстрый круг. Шаг 7 — медленный, но он и есть тест
на совпадение с GitHub: `.github/workflows/ci.yml` делает ровно `composer install` без lock, а значит разрешает
зависимости заново, и локальный зелёный результат без шага 7 не является доказательством, что CI зелёный. Копия
собирается так, чтобы повторяла состояние рабочего дерева (включая незакоммиченные правки), `vendor/` и `composer.lock`
в неё не копируются, каталог после проверки удаляется. Недоступный шаг — честно в отчёт.

Все шаги обязательны. Недоступный шаг — честно в отчёт. Отчёт по Gate пишется в файл сессии (4.1): команды и их
результаты, без приписывания того, чего не запускалось. Помни, что красный Gate — не «почти готово»: см. §10 gotcha 1
про текущее состояние ветки `dev`.

## 8. OWASP Top 10 (обязательно при написании кода)

- **A01** — доступ к странице, роуту вложений и счётчика непрочитанного только по правам (`permissions.view`);
  fail-closed, JSON 401/403 для неавторизованного/без прав, отправка — по `permissions.answer`.
- **A02** — секреты только в env; вложения — вне public-корня.
- **A03** — HTML операторов санитизируется `TextSanitizer` (whitelist тегов MAX) до сохранения и до отправки;
  Blade-экранирование по умолчанию.
- **A04** — лимиты загрузки (`attachments.upload_max_kb`, whitelist `attachments.mimes`, жёсткий `max_bytes` при
  скачивании); входящие URL медиа — только домены CDN MAX.
- **A05** — publishable-конфиг с безопасными дефолтами; `route.enabled=false` отключает оба роута полностью; `env()`
  читается только в конфиге.
- **A06** — `composer security-audit` в Gate и CI; зависимости без зафиксированного lock, поэтому дрейф версий проверяй
  честно (gotcha 10).
- **A07** — гостю на роут вложений — редирект на `route.login_url`; постоянновременные сравнения — зона ответственности
  laravel-max-client.
- **A08** — загрузка и обработка вложений не доверяют имени файла и MIME: whitelist расширений, проверка размера,
  хранение вне public, отдача только через авторизованный контроллер.
- **A09** — ошибки отправки в MAX логируются без чувствительных данных (без токена, текстов переписки и payload
  апдейтов); исключения не глушатся молча, пользователь видит понятное уведомление.

## 9. Источник истины (MAX API)

- Спецификация: https://github.com/geekcodev/max-openapi (OpenAPI 3.1), сервер `https://platform-api2.max.ru`.
- Сводка эндпоинтов, DTO и enums: `max-php-client/docs/api-reference.md` в соседнем репозитории — не дублируй её здесь,
  читай.
- Реестр чатов и пользователей — из `geekcodev/laravel-max-client` (`MaxChat`, `MaxUser`, `MaxChatUser`,
  `MaxUserProfileService`, `MaxChatProfileService`, `MaxChatStatus`).
- Факты, влияющие на плагин: аутентификация — заголовок `Authorization: <token>` **без** `Bearer`; все timestamp API —
  Unix в **миллисекундах**; `chat_id`/`user_id` — int64 и могут быть **отрицательными** (группы и каналы);
  `message_id` — строка; пагинация — `marker` + `count`; загрузка медиа идёт через `uploadMedia` и требует ожидания
  готовности вложения; rate limit отправки — 2/сек на чат.
- Сигнатуры брать из пакетных классов, не выдумывать. Прод-поведение важнее спеки в случаях, перечисленных в
  `docs/api-reference.md` ядра.

## 10. Частые ошибки (gotchas)

1. **Сверь форму реестра с фактически установленным `laravel-max-client`.** Constraint `^1.2.0` в `composer.json`
   жёсткий, но `composer.lock` не коммитится — установленная версия может отличаться. Код плагина рассчитан на форму 1.2
   (`max_chats.chat_id` — PK, связи в `max_chat_users`); на 1.1.x PHPStan и тесты красные. Проверка:
   `composer show geekcodev/laravel-max-client` + §5 «Схема v1.2.0 реестра». Если версия адаптера снова поменяет форму
   реестра, план перевода — `.agents/plans/PLAN-filament-max-chat.md`.
2. **Порядок provider'ов в Testbench**: Livewire подключай **последним** (`tests/TestCase.php`). Если Livewire
   зарегистрировать раньше Filament, `SupportServiceProvider` перебьёт биндинг хранилища состояния Livewire, и состояние
   теряется между вызовами — тесты падают странно.
3. **Тесты зависят от формы реестра соседнего пакета**: `TestCase` грузит миграции прямо из
   `vendor/geekcodev/laravel-max-client/database/migrations`. Смена формы реестра там ломает и наши фикстуры — сначала
   проверь миграции адаптера, потом свои тесты.
4. **Filament v5, `counts()`** ждёт имя связи, а не колонки: `counts('chatUsers')`. Передача имени колонки или пустой
   вызов ничего не считает.
5. **Filament v5, поиск**: `->searchable()` больше не принимает `columns:` — передаётся массив
   `->searchable(['title'])`.
6. **Filament v5, ссылки**: у `TextEntry` внешняя ссылка — `->url(...)->openUrlInNewTab()`; `->openInNewTab()` не
   существует и даёт `BadMethodCallException`.
7. **Filament v5, подписи**: `TextEntry::make('name')` в состоянии `false` не рендерится — тест «видно подпись» на
   `false`-значении не проходит и вводит в заблуждение; проверяй значение атрибута, а не наличие метки.
8. **В тестах очередь подменена**: `Queue::fake()` в `TestCase`. Убери его — и `RefreshChatProfilesJob` при
   `QUEUE_CONNECTION=sync` уйдёт в реальный API MAX (и уронит тесты по сети/токену).
9. **Риск с exception handlers**: PHPUnit с `failOnRisky=true` ругается «did not remove its own error handlers» — обычно
   из-за мока/перехвата, который не снимается в `tearDown` (например, `MaxChatSender` в тестах на ошибку отправки).
10. **Кэш PHPStan врёт**: устаревший `.phpstan-cache` даёт ложные краши Larastan
    (`Undefined constant Larastan\Larastan\LARAVEL_VERSION`) — лечится `rm -rf .phpstan-cache`.
11. **Packagist из контейнера**: если `composer install/update` или `composer security-audit` зависает на сети (curl
    error 28, `Connection timed out`), помогает `COMPOSER_IPRESOLVE=4`
    (`docker compose run --rm -e COMPOSER_IPRESOLVE=4 app composer security-audit`); это временный флаг, в репозиторий
    его не коммитим. Ошибку `Cannot create cache directory /.cache/composer` считать не результатом аудита — контейнер
    работает от пользователя 1000:1000.
12. **`docker compose exec` без `-T`** ломает пайпы и вывод PHPUnit/PHPStan — для неинтерактивных запусков добавляй
    `-T`.
13. **Покрытие падает незаметно**: `composer test` отчёт не строит. После добавления кода гейт проверяет
    `composer coverage`; в контейнере нужен `XDEBUG_MODE=coverage`, в CI — `coverage: xdebug`.
14. **Gate проверяется только в условиях CI, а локальный `vendor/` в нём не участвует.** Локальный `composer.lock`
    скрывает дрейф зависимостей, поэтому зелёный результат на текущем `vendor/` ничего не доказывает: CI делает ровно
    `composer install` без lock и каждый раз получает другие версии Filament/Livewire/Larastan (`composer.lock` в
    `.gitignore`). Обязательный шаг 7 Gate (§7): копия рабочего дерева без `vendor/` и `composer.lock` в `.ci-sim/`,
    затем `composer install` и весь Gate заново внутри копии — с теми версиями, которые получит CI. Каталог после
    проверки удаляется, в `.gitignore` не нужен. На релизе v1.1.0 локально было зелёно (Larastan 3.11.0), а на свежем
    резолве 3.12.2 красным оказался `View::make()` в `FilamentMaxChatPlugin` (gotcha 20) — то есть локальный круг
    баг не видел вовсе.
15. **Дрейф PHPStan и baseline/ignoreErrors**: в CI Filament/Livewire/Larastan новее, чем локально, поэтому часть
    хелперных ошибок там просто не возникает, и незакрытая запись `ignoreErrors` роняет `composer analyse` при зелёном
    коде. Поэтому `phpstan.neon` содержит `reportUnmatchedIgnoredErrors: false`; игнорируются **только** записи про
    хелперы из `tests/`. Production-ошибки в ignore не добавлять — чинить код.
16. **Идентификаторы MAX бывают отрицательными** (чаты групп и каналов) — не пиши тесты и фильтры в расчёте на
    положительные id; `unsignedBigInteger` отверг бы такой идентификатор на MySQL, а SQLite это не воспроизводит —
    знаковость проверяется по исходникам миграций.
17. **`.gitattributes`**: нужен паттерн `/.agents/**` — с завершающим слешем `git check-attr export-ignore` молча отдаёт
    `unspecified`.
18. **Ловушка репозитория**: release-notes и планы больше не создаются в корне (`RELEASE-vX.Y.Z.md`,
    `PLAN-*.md`) — только в `.agents/` (правило 2.1, форматы в 4.1).
19. **PyYAML молча перезатирает дубликаты ключей**: `yaml.safe_load` на workflow с двумя `name:` в одном job вернёт
    корректный словарь и ошибку не покажет (GitHub Actions и IDE такую ошибку, наоборот, подсвечивают). При правке
    `.github/workflows/*.yml` проверяй строгим загрузчиком с проверкой на дубликаты или `actionlint`; в dev-контейнере
    `actionlint` и `yamllint` не установлены, поэтому проверка — глазами плюс строгий разбор.
20. **Дрейф PHPStan по `view-string` лечится типизацией, а не ignore.** На локальном Larastan 3.11.0 вызов
    `View::make('filament-max-chat::components.notification-script')` считается корректным, а на 3.12.2 в CI — нет
    (`expects view-string, string given`): литерал в phpstan-типы не попадает, именованные аргументы не помогают.
    Рабочий способ — вынести имя в переменную с `/** @var view-string $notificationScript */`. Подавление через
    `@phpstan-ignore-next-line` в `src/` запрещено правилом gotcha 15 и §5 «Соглашения»: это настоящая ошибка типов,
    просто локально не воспроизводится, поэтому проверяй такие места через gotcha 14, а не «по локальному зелёному».
21. **Порядок миграций — это порядок имён файлов, и SQLite его не проверяет.** `Migrator::getMigrationFiles()` сортирует
    миграции приложения и всех пакетов по имени файла, поэтому внешний ключ допустим только на таблицу, чья миграция
    имеет меньшее имя. Полоса в имени миграции — это позиция пакета в общем порядке, а не отдельный именованный
    диапазон: плагин занимает `0000_02`, между `laravel-max-client` (`0000_00`, создаёт `max_chats` и `max_users`) и
    приложением, чьи `users` идут в `0000_01`. Регрессия v1.1.0 была ровно этим: `0001_01_01_000001_create_max_chat_messages_table`
    ссылалась на `max_chats`, которую `laravel-max-client` создавал миграцией `0001_01_01_000002`, то есть позже неё,
    и чистая установка падала на PostgreSQL и MySQL с «relation "max_chats" does not exist». На SQLite таблица с
    внешним ключом на несуществующую таблицу создаётся молча, а тестовая фикстура `users` лежит под именем
    `0000_01_000000`, то есть штатный набор тестов такой дефект не видит в принципе. Поэтому порядок проверяется по
    исходникам миграций — `tests/Feature/MigrationOrderTest.php` — и новый внешний ключ не считается готовым без него.
    Побочный эффект переименования: у установки, прошедшей старую версию, новое имя числится невыполненным при уже
    существующей таблице, поэтому в `up()` create-миграции стоит `Schema::hasTable()`. Пара полос несамостоятельна:
    `0000_02` работает только с адаптером, чьи миграции лежат в `0000_00`, поэтому релиз полосы плагина и релиз полосы
    адаптера должны выходить вместе, а constraint на адаптер — поднимать на версию с этой полосой: с адаптером на
    `0001_01_01_*` (v1.2.0) плагин в `0000_02` встаёт **перед** ним, и регрессия возвращается. Сквозная проверка по
    миграциям всех владельцев сразу живёт в приложении (`tests/Feature/MigrationOrderTest.php` там): в vendor пакета стоит
    установленная версия `laravel-max-client` с её собственными именами, поэтому читать чужие файлы отсюда нельзя.

## 11. Чек-лист перед завершением задачи

- [ ] Gate пройден целиком: lint 0 файлов, PHPStan 0 ошибок, PHPUnit зелёные, покрытие ≥95%, audit чист.
- [ ] Gate пройден на чистом резолве в `.ci-sim/` (шаг 7 §7, gotcha 14): без локальных `vendor/` и `composer.lock`;
      зелёный результат на текущем `vendor/` без этого шага не засчитывается.
- [ ] Новый код покрыт тестами (unit — сервисы/sanitizer/модели, feature — Livewire, контроллеры, права, отказ при
  отсутствии прав).
- [ ] Публичный API не сломан: сигнатуры, ключи конфига, ключи переводов и дефолты совместимы с patch-релизом.
- [ ] Проверки прав fail-closed: без нужного `permissions.*` страница недоступна, роут вложений и счётчик отвечают
  401/403, действие скрыто и отклоняется на сервере.
- [ ] Нет прямых вызовов `ApiClient` из Livewire/страниц/контроллеров; отправка — через `MaxChatSender`, профиль — через
  `MaxUserProfileService`.
- [ ] Каждый новый ключ перевода присутствует и в `lang/ru`, и в `lang/en`; новая переменная — в `.env.example` и
  `config/filament-max-chat.php`.
- [ ] Секретов нет в коде, логах, коммитах; `README.md`, `.env.example` и `AGENTS.md` синхронны с кодом.
- [ ] Обновлены `.agents/plans/PLAN-filament-max-chat.md` и `.agents/journals/` (строка в `JOURNAL.md` + файл в
  `sessions/` по формату 4.1).
- [ ] Коммит/тег/push — только по явному запросу пользователя; иначе оставлено рабочее дерево и описан статус.
- [ ] Если просили краткий текст коммита: только head, на английском по Conventional Commits, собран по всему diff ветки
  (не по последнему действию), коммит не создан. Body — только если просили отдельно.