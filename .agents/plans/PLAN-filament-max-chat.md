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

Состояние на 2026-10-02: ветка `dev` содержит код под **старую** форму реестра (`max_chats.id`, `unsignedBigInteger` в
`max_chat_messages`), а `composer install` подтягивает `laravel-max-client` 1.2 (`max_chats.chat_id` — первичный ключ,
связи в `max_chat_users`). Constraint `^1.1.0` в `composer.json` разрешает обе формы, поэтому расхождение не видно до
Gate. Адаптация уже сделана в ветке `feat/max-chat-registry-v1-2` (коммиты `dcc7a48`, `b64d47e`, `4ab6783`) и в `dev`
**не слита**.

Текущий красный Gate на `dev` (проверено в контейнере, Xdebug без `coverage`):

- `composer lint` — 0 файлов с правками;
- `composer analyse` — 81 ошибка, из них 70 `Access to an undefined property MaxChat::$id` в `Models\MaxChat`, 6 в
  `Services\ChatProfileRefresher`, 5 в `Services\MaxMessageService`;
- `composer test` — 207 тестов, 97 errors, 12 failures, 13 risky;
- покрытие и audit не имеет смысла считать, пока тесты красные.

Что сделать:

- [ ] Взять `feat/max-chat-registry-v1-2` и **слить в `dev`**, разрулив конфликты в `Models\MaxChat`,
  `Services\MaxMessageService`, `Services\ChatProfileRefresher` и `database/migrations`;
- [ ] Проверить, что в `MaxMessageService` адресация истории идёт по `chat_id`, а `displayName()` не делает запрос на
  каждую строку списка;
- [ ] Проверить, что `max_chat_messages.max_chat_id` стал знаковым `bigInteger` и ссылается на `max_chats.chat_id`;
- [ ] Поднять `composer.json` до `geekcodev/laravel-max-client: ^1.2.0` — на 1.1 у моделей нет `title`, `chat_type`,
  `status`, ресурс рассыпается (AttributeError), понижать constraint нельзя;
- [ ] Синхронизировать `README.md` («Требования», «Обновление с v1.x») и `.agents/release/RELEASE_NOTES_vX.Y.Z.md`
  под выбранный номер релиза;
- [ ] Прогнать Gate целиком, включая `composer coverage`; результат — в файл сессии;
- [ ] Отдельно: снять `fcache`/зафиксировать, что `php artisan max:upgrade` в хосте нужно выполнить после `migrate`
  (см. README и `laravel-max-client`).

Историческая справка: план по `storeIncomingForUser` — `plans/2026-09-03-store-incoming-for-user.md` (завершён,
реализован коммитом `b53189c`).

## 2. Регрессия PHPStan на зависимостях и в условиях CI

- [ ] Один раз прогнать Gate «в условиях CI»: скопировать дерево без `vendor/` и `composer.lock` в `.ci-sim/`, выполнить
  там `composer install` и весь Gate (gotcha 14). Проверка нужна, потому что локальный lock скрывает дрейф
  Filament/Livewire/Larastan;
- [ ] Убедиться, что `reportUnmatchedIgnoredErrors: false` действительно понадобился: если в условиях CI хелперные
  ошибки из `tests/` не возникают — запись в `ignoreErrors` можно сузить, production-ошибки туда не добавлять;
- [ ] Закрыть запись `ignoreErrors` про `view()` в `src/Livewire/OperatorChat.php` (ошибка живая, ignore её прячет):
  типизировать вызов, а не оставлять подавление в конфиге — gotcha 15 и §5 «Соглашения» это запрещают;
- [ ] `composer coverage` в CI уже переведён на `coverage: xdebug`; проверить, что порог 95% достижим после слияния
  ветки 1.2, иначе сначала закрыть тестами непокрытые ветки, а не снижать порог.

## 3. Release notes и README

- [ ] Определить номер релиза, который включит перевод на 1.2 (ломающий переход — minor или patch с `BREAKING CHANGE`),
  и написать `.agents/release/RELEASE_NOTES_vX.Y.Z.md` в формате `Новое` / `Изменение (BC)` / `Затронутые сценарии` /
  `Качество`;
- [ ] Дублировать значимые пункты в `README.md`, раздел «История изменений»;
- [x] Проверить, что `README.md` не обещает того, чего нет в коде: «Требования» теперь требуют `laravel-max-client`
  ^1.2.0 и `max-php-client` ^1.1.8 (фактически установлены 1.2.0 и 1.1.8) с явной сноской про исторический constraint
  `^1.1.0` в `composer.json`; сам constraint поднимается пунктом 1.

---

## Журнал решений

**2026-10-02.** Рабочая память перенесена из корня в `.agents/`: 11 release-notes получили имена
`RELEASE_NOTES_vX.Y.Z.md` и уехали в `.agents/release/`, план `storeIncomingForUser` — в
`.agents/plans/2026-09-03-store-incoming-for-user.md`, создан `plans/PLAN-filament-max-chat.md`, журнал сессий и файл
сессии. Каталог остаётся в git, но исключён из архива пакета через `export-ignore` — так память переживает `git clone`
и не попадает в дистрибутив Packagist (решение пользователя, по образцу `filament-max-users`).

**2026-10-02.** Constraint `laravel-max-client` намеренно не поднят до `^1.2.0` в этой сессии: пока перевод не слит,
такая правка объявила бы совместимость, которой на `dev` нет. Поднятие — пункт 1 плана, вместе со слиянием ветки.

**2026-10-02.** Число «95%» в гейте покрытия взято как в соседних пакетах; фактическое покрытие на текущем состоянии не
измерено (тесты красные), гейт включается и проверяется в пункте 2.
