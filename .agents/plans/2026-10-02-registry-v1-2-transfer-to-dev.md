# Перевод на реестр v1.2.0 — перенос из feature-ветки в `dev`

> Задача: три коммита ветки `feat/max-chat-registry-v1-2` перенести в `dev` **без коммитов**, проверить на
> соответствие `AGENTS.md` и описать состояние, чтобы работу можно было продолжить в следующей сессии.
> Файл не удаляется; закрытые пункты остаются с отметкой `[x]`. Общий план пакета — `PLAN-filament-max-chat.md`.

## Статус на конец сессии

Перенос сделан полностью, код в рабочем дереве `dev`, HEAD не сдвинут (`94210e6`), коммитов не создано. Gate на
перенесённом состоянии зелёный, кроме недоступного по сети audit. Остались пункты, которые нельзя закрыть без
пользователя (коммит, тег) или без внешнего окружения (сеть, эталонное приложение).

Дополнение того же дня: по решению пользователя отдельная миграция перевода
`0001_01_01_000002_repoint_max_chat_messages_fk` удалена, обе фазы выполняет команда `max-chat:upgrade`. Подробности в
журнале сессии `journals/sessions/2026-10-02-drop-translation-migration.md`.

## Что перенесено

`git cherry-pick -n dcc7a48 b64d47e 4ab6783` — три коммита:

1. `dcc7a48 feat(chat): address message history by chat_id for v1.2.0 chat registry` — адресация истории по `chat_id`,
   знаковые `bigInteger`, `MaxChat::interlocutor()` / `interlocutorId()` / `displayName()` вместо `maxUser()`, поиск по
   всем пользователям чата через `max_chat_users`, `chatExists()` и `resolveInternalIdFromMaxChatId()` как
   deprecated-шим, фикстуры `tests/Support/MakesChats.php`;
2. `b64d47e feat(migration): translate message history in two phases via max-chat:upgrade` — две фазы перевода
   (`ChatMessagesSchemaRepoint::remap()` в миграции `0001_01_01_000002_repoint_max_chat_messages_fk`,
   `repin()` в команде), `Console\MaxChatUpgradeCommand` (`max-chat:upgrade`), регистрация команды в провайдере,
   `tests/Support/InspectsChatSchema.php`, три новых feature-теста. Позже миграция удалена, тесты двух фаз объединены в
   `ChatMessagesSchemaRepointTest` (см. дополнение в статусе сессии);
3. `4ab6783 docs: document v1.2.0 registry translation and max-chat:upgrade` — документация, слитая вручную.

Конфликты cherry-pick были только в `AGENTS.md` и `README.md` (их сильно переписал коммит `94210e6` на `dev`). Разрешены
в пользу версии `dev` с добавлением фактов о переводе; sequencer снят через `git cherry-pick --quit`.

Проверка полноты переноса: `src/`, `tests/`, `database/`, `resources/` в рабочем дереве **побайтово совпадают** с
`4ab6783` (сверка `git archive 4ab6783` + `diff -r`). Расходятся только документация и `composer.json` — там учтено
состояние `dev`.

## Соответствие AGENTS.md (проверено)

- [x] Constraint `geekcodev/laravel-max-client: ^1.2.0` в `composer.json`, фактически установлен 1.2.0 (сверено
  `composer show`), форма реестра совпадает (§5 «Схема v1.2.0 реестра»);
- [x] Миграции аддитивные: create-миграция переписана под форму 1.2 (FK на `max_chats.chat_id`, знаковые
  `bigInteger`), перевод истории делает команда `max-chat:upgrade` (см. дополнение сессии);
- [x] Никаких прямых вызовов `ApiClient` из Livewire/страниц/контроллеров: отправка через `MaxChatSender`, профиль через
  `MaxUserProfileService` (троттлит `ChatProfileRefresher` + `RefreshChatProfilesJob`);
- [x] Права fail-closed не тронуты: страница и оба роута по `permissions.view`, отправка по `permissions.answer`;
- [x] Ни одного нового ключа перевода не добавлено — команда `max-chat:upgrade` печатает текст консоли напрямую,
  lang-файлы не менялись; в `lang/ru` и `lang/en` ключи синхронны;
- [x] Нет секретов и новых зависимостей; `version` в `composer.json` не появилась;
- [x] BC: публичная сигнатура `MaxMessageService::resolveInternalIdFromMaxChatId()` сохранена и помечена
  `@deprecated`; `MaxChat::maxUser()`/`conversationName()` убраны — это ломающее изменение, оно описано в release notes
  v1.1.0 и README;
- [x] `AGENTS.md` §1 «Статус и версии» и §10 gotcha 1 переписаны под фактическое состояние (код под форму 1.2, Gate
  зелёный, аудит за переносом не гонялся).

## Осталось сделать (в следующих сессиях)

- [ ] **Коммит перенесённого кода в `dev`** — по явному запросу пользователя. Перед коммитом `git status --short` и
  `git diff --cached`, в индекс — явный список файлов (все 28 файлов из переноса плюс обновлённые `AGENTS.md`,
  `README.md`, `PLAN-filament-max-chat.md`, этот файл и файлы журнала). `git add .` не использовать.
- [ ] **Релиз v1.1.0**: release-notes уже написаны (`.agents/release/RELEASE_NOTES_v1.1.0.md`, история в README есть).
  Шаги по `AGENTS.md` §2: `merge dev → main` → `git tag v1.1.0` → `git push origin v1.1.0` → GitHub Release из тега →
  Packagist обновится по webhook.
- [ ] **`composer security-audit`** — прогнать при доступной сети. В сессии переноса packagist был недоступен дважды
  (curl error 28), в том числе с `COMPOSER_IPRESOLVE=4`; причина сети, не кода. Тот же audit в предыдущей сессии
  проходил чисто, новых зависимостей не добавлялось.
- [ ] **Проверка перевода на интеграционном приложении** (любом, где пакет уже стоял до v1.1.0): `php artisan migrate`
  → `php artisan max-chat:upgrade`, затем список диалогов, лента и отправка ответа. Регрессия, всплывшая там, чинится
  здесь (`AGENTS.md` §1), а не обходом в стороннем репозитории.
- [ ] **Мёртвый ключ перевода** `fallback_user` в `lang/ru/chat.php` и `lang/en/chat.php`: после перехода на
  `displayName()` он не используется — fallback `'chat ' . $chat_id` повторяет поведение адаптера. Либо вернуть ключ в
  `MaxChat::displayName()`, либо удалить из обоих lang-файлов; решение принять перед релизом, чтобы в v1.1.0 не уехали
  неиспользуемые ключи (это не BC-риск: ключ никто не читал из опубликованных копий).
- [ ] **Пункт 2 общего плана**: Gate «в условиях CI» (дерево без `vendor/` и `composer.lock`, `composer install`, весь
  Gate — gotcha 14) и сужение записи `ignoreErrors` про `view()` в `src/Livewire/OperatorChat.php` (gotcha 15).
- [ ] **Согласовать требования к ядру**: README требует `geekcodev/max-php-client` ^1.1.8, а constraint в
  `composer.json`
  остался `^1.1.0` (фактически установлен 1.1.8). Расхождение было до переноса; решить, поднимать ли constraint до
  `^1.1.8` или ослабить формулировку README, и держать оба источника синхронными (`AGENTS.md` §1 и правило 3.8).

## Как продолжить

```bash
cd /home/user/web/filament-max-chat
git status --short            # 28 файлов переноса + документация, всё в индексе (cherry-pick -n)
git log --oneline -1          # 94210e6 — коммитов не создано
docker compose exec -T app composer test    # быстрая проверка перед коммитом
```

Состояние индекса: изменения уже добавлены (`git cherry-pick -n` кладёт их в индекс), конфликтные файлы разрешены и
добавлены вручную. Перед коммитом пользователь решает, оставлять ли изменения в индексе или снять их
(`git restore --staged <файлы>`), и пишет текст коммита сам.

## Журнал решений

**2026-10-02.** Перенос сделан в рабочее дерево, а не мержем: пользователь явно попросил перенести изменения без
коммитов, поэтому `git cherry-pick --quit` вместо `--continue`, HEAD на месте.

**2026-10-02.** Конфликты `AGENTS.md` и `README.md` разрешены в пользу версии `dev` (переписанной в `94210e6`) с
добавлением фактов о переводе на реестр 1.2 и удалением утверждений «перевод не слит, Gate красный», которые после
переноса неверны. Кодовые файлы не конфликтовали и перенесены без изменений.

**2026-10-02.** `composer security-audit` не прогонялся успешно (сеть до packagist недоступна дважды, включая
`COMPOSER_IPRESOLVE=4`), поэтому в статусе он помечен как непроверенный, а не как пройденный.