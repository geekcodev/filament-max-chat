# PLAN — filament-max-chat

> Рабочий план пакета `geekcodev/filament-max-chat`. Активен, пока в нём есть невыполненные пункты `[ ]`.
> По мере работы отмечай пункты `[x]`, дописывай решения в конец файла (журнал решений) и не удаляй закрытые разделы.
> Формат рабочей памяти и место хранения файлов — `AGENTS.md`, разделы 4.1 и 10. Эталон документированного workflow —
> соседний плагин `/home/user/web/filament-max-users/`, источник истины по API MAX —
> `../max-php-client/docs/api-reference.md`.

Задачи в этом плане переживают одну сессию. Отдельные многошаговые задачи оформляются отдельными файлами
`plans/YYYY-MM-DD-<слаг>.md`; готовый файл не удаляется, а помечается завершённым.

---

## 1. Слить перевод на форму реестра `laravel-max-client` 1.2 (блокирует Gate)

Состояние на 2026-10-02: ветка `dev` содержала код под **старую** форму реестра (`max_chats.id`, `unsignedBigInteger` в
`max_chat_messages`), а `composer install` подтягивает `laravel-max-client` 1.2 (`max_chats.chat_id` — первичный ключ,
связи в `max_chat_users`). Constraint `^1.1.0` в `composer.json` разрешал обе формы, поэтому расхождение не было видно
до Gate. Адаптация была сделана в ветке `feat/max-chat-registry-v1-2` (коммиты `dcc7a48`, `b64d47e`, `4ab6783`).

**Перенесено 2026-10-02** (вторая сессия того же дня): все три коммита перенесены в рабочее дерево `dev` без коммитов
(`git cherry-pick -n`, конфликты только в `AGENTS.md` и `README.md`, разрешены вручную). Подробности и оставшиеся
пункты —
`plans/2026-10-02-registry-v1-2-transfer-to-dev.md`. Gate на перенесённом состоянии: lint 0 · PHPStan 0 · 224 теста ·
покрытие 96.98% · audit недоступен (сеть до packagist).

Что сделано:

- [x] Взять `feat/max-chat-registry-v1-2` и перенести в `dev`, разрулив конфликты в `AGENTS.md` и `README.md`
  (код `src/`, `tests/`, `database/`, `resources/` перенесён байт в байт, совпадает с веткой);
- [x] Проверить, что в `MaxMessageService` адресация истории идёт по `chat_id`, а `displayName()` не делает запрос на
  каждую строку списка (связь `chatUsers.maxUser` eagerly-loaded в `conversations()` и `searchConversations()`);
- [x] Проверить, что `max_chat_messages.max_chat_id` стал знаковым `bigInteger` и ссылается на `max_chats.chat_id`;
- [x] Поднять `composer.json` до `geekcodev/laravel-max-client: ^1.2.0`;
- [x] Синхронизировать `README.md` («Требования», «Архитектура», «Обновление с v1.0.x на v1.1.0») и
  `.agents/release/RELEASE_NOTES_v1.1.0.md`;
- [x] Прогнать Gate целиком, включая `composer coverage`; результат — в файле сессии.

Что осталось за пользователем или на будущее:

- [ ] Коммит перенесённого кода в `dev` (делает пользователь; отдельные файлы в индекс, не `git add .`);
- [ ] Релиз: release-notes v1.1.0 уже написаны, нужно `merge dev → main` → `git tag v1.1.0` → GitHub Release →
  Packagist;
- [ ] `composer security-audit` прогнать при доступной сети (в сессии переноса packagist был недоступен, оба раза curl
  error 28).

## 1.1 Чаты без собеседника в реестре (группа, канал, `max_chat_users` не синхронизирован)

Состояние на 2026-10-02: у чата, чей состав ещё не в реестре, `interlocutor()` возвращает `null`, и плагин терял такие
сообщения. Плагин починен полностью (nullable `user_id`, `LEFT JOIN` в поиске, fallback-подписи), состав чата — это зона
адаптера.

- [x] `max_chat_messages.user_id` nullable: отдельной аддитивной миграцией `0001_01_01_000003`, create-миграция осталась с `NOT NULL`; откат подставляет `chat_id`;
- [x] `storeIncoming()` сохраняет входящее при пустом sender, `storeOutgoing()` принимает `?int`, Livewire не теряет
  ответ;
- [x] `searchConversations()` на `LEFT JOIN` — чат без участников находится по тексту сообщений;
- [x] UI: `senderFallbackName()` по типу чата, пустая карточка собеседника не рисуется, ключи в `lang/ru` и `lang/en`;
- [x] `README.md`, `AGENTS.md`, release notes и файл сессии синхронизированы;
- [ ] Перенести план `plans/2026-10-02-laravel-max-client-chat-member-sync.md` в `laravel-max-client` и реализовать
  `syncChatMembers()`, `user_added`/`user_removed`, `max-chat:sync-members` (делается в адаптере);
- [x] Правка и удаление сообщений: `applyIncomingEdit()` и `applyIncomingRemoval()` (поиск по паре «`chat_id` +
  `message_id`», удаление подчищает файл вложения), документированы в README и release notes;
- [ ] `dialog_cleared` не делаем сознательно: очистка ленты целиком необратима, решение за приложением.

Историческая справка: план по `storeIncomingForUser` — `plans/2026-09-03-store-incoming-for-user.md` (завершён,
реализован коммитом `b53189c`).

## 2. Регрессия PHPStan на зависимостях и в условиях CI

- [x] Один раз прогнать Gate «в условиях CI»: скопировать дерево без `vendor/` и `composer.lock` в `.ci-sim/`, выполнить
  там `composer install` и весь Gate (gotcha 14). Проверка нужна, потому что локальный lock скрывает дрейф
  Filament/Livewire/Larastan — и не зря: на свежем резолве (Larastan 3.12.2 вместо 3.11.0) красным оказался
  `View::make()` в `FilamentMaxChatPlugin` (аргумент не `view-string`), локально этот баг не воспроизводился;
- [ ] Убедиться, что `reportUnmatchedIgnoredErrors: false` действительно понадобился: если в условиях CI хелперные
  ошибки из `tests/` не возникают — запись в `ignoreErrors` можно сузить, production-ошибки туда не добавлять;
- [ ] Закрыть запись `ignoreErrors` про `view()` в `src/Livewire/OperatorChat.php` (ошибка живая, ignore её прячет):
  типизировать вызов, а не оставлять подавление в конфиге — gotcha 15 и §5 «Соглашения» это запрещают. Способ,
  которым закрыт такой же случай в `FilamentMaxChatPlugin`, — именованная переменная с `@var view-string`;
- [ ] `composer coverage` в CI уже переведён на `coverage: xdebug`; проверить, что порог 95% достижим после слияния
  ветки 1.2, иначе сначала закрыть тестами непокрытые ветки, а не снижать порог.

## 3. Release notes и README

- [x] Определить номер релиза, который включит перевод на 1.2 — **v1.1.0** (ломающий переход, minor), release-notes
  написаны: `.agents/release/RELEASE_NOTES_v1.1.0.md`;
- [x] Дублировать значимые пункты в `README.md`, раздел «История изменений» (строка v1.1.0);
- [ ] Порелизные шаги: `merge dev → main`, `git tag v1.1.0`, GitHub Release из тега, Packagist обновится по webhook;
- [x] Проверить, что `README.md` не обещает того, чего нет в коде: «Требования» требуют `laravel-max-client` ^1.2.0 и
  `max-php-client` ^1.1.8, constraint в `composer.json` поднят до `^1.2.0`.

---

## Журнал решений

**2026-10-02.** Рабочая память перенесена из корня в `.agents/`: 11 release-notes получили имена
`RELEASE_NOTES_vX.Y.Z.md` и уехали в `.agents/release/`, план `storeIncomingForUser` — в
`.agents/plans/2026-09-03-store-incoming-for-user.md`, создан `plans/PLAN-filament-max-chat.md`, журнал сессий и файл
сессии. Каталог остаётся в git, но исключён из архива пакета через `export-ignore` — так память переживает `git clone`
и не попадает в дистрибутив Packagist (решение пользователя, по образцу `filament-max-users`).

**2026-10-02.** Constraint `laravel-max-client` намеренно не поднят до `^1.2.0` в этой сессии: пока перевод не слит,
такая правка объявила бы совместимость, которой на `dev` нет. Поднятие — пункт 1 плана, вместе со слиянием ветки.

**2026-10-02.** Пустой `max_chat_users` у канала или группы признан штатной ситуацией, а не ошибкой данных: отправка в
MAX идёт на `chat_id` без `user_id`, поэтому плагин хранит такую переписку, ищет её по тексту и подписывает отправителя
по типу чата. Синхронизация состава делегирована адаптеру отдельным планом — вызов `getChatMembers()` из плагина нарушил
бы контракт «профили только через `MaxUserProfileService`».

**2026-10-02.** По решению пользователя в релиз вошли `message_edited` и `message_removed`, а `dialog_cleared`
оставлен за приложением: удаление строки и файла вложения обратимо в том смысле, что приводит локальную историю к
состоянию MAX, а очистка ленты целиком стирает переписку без спроса.

**2026-10-02.** Число «95%» в гейте покрытия взято как в соседних пакетах; фактическое покрытие на текущем состоянии не
измерено (тесты красные), гейт включается и проверяется в пункте 2.
