---
tags: [ workflow, agents, coverage, ci, docs, gate ]
date: 2026-10-02
---

# Организация работы: рабочая память, gotchas, гейт покрытия

## Проблема

`AGENTS.md` пакета (150 строк) описывал только устройство плагина и не описывал процесс: не было рабочего журнала в
едином месте, раздела «Частые ошибки», чек-листа перед завершением задачи, гейта покрытия и правил git. Release notes и
план лежали в корне как `RELEASE-vX.Y.Z.md` и `PLAN-*.md` — двенадцать файлов в корне, все вне git. Гейт покрытия в
`.agents/plans/2026-09-03-store-incoming-for-user.md` был записан как обязательный шаг, но ни `composer coverage`, ни
`scripts/check-coverage.php`, ни драйвера покрытия в CI не существовало — падение покрытия было бы незаметным.

Попутно выяснилось, что Gate на `dev` красный, и это никем не зафиксировано.

## Решение

- **Рабочая память в `.agents/`**: `plans/` (активный `PLAN-filament-max-chat.md` и
  `2026-09-03-store-incoming-for-user.md`),
  `release/RELEASE_NOTES_vX.Y.Z.md` (11 файлов переехали из корня), `journals/{JOURNAL.md,sessions/}`. Каталог остаётся
  в git, но исключён из архива пакета через `export-ignore` в `.gitattributes` — память переживает `git clone` и не
  попадает в дистрибутив. Отдельные файлы сессии по датам до 2026-10-02 не восстанавливались: в `JOURNAL.md` они
  отмечены как восстановленные по `git log`, без выдуманного содержания.
- **`AGENTS.md`** переписан по образцу `filament-max-users` (соседний Filament-плагин) и `laravel-max-client`: ветка
  `dev` и релизный процесс с тегом, различение «текст коммита» и «коммит», запрет `git add .`, subject по всему diff
  ветки на английском, динамическая сверка версий вместо цифр в файле, правило «регрессия из интеграционного приложения
  чинится здесь», синхронизация `.env.example` ↔ config и lang `ru`/`en`, таблица «что куда писать», BC-правило для
  patch-релиза, раздел «Источник истины (MAX API)» без дублирования `max-php-client`, 18 gotchas (Filament v5,
  Testbench, PHPStan-кэш, `COMPOSER_IPRESOLVE`, `-T`, дрейф CI, знаковые идентификаторы) и чек-лист из 10 пунктов.
- **Гейт покрытия**: `scripts/check-coverage.php` (порог 95% строк, аргумент переопределяет), скрипт
  `composer coverage` (`--coverage-clover build/coverage.xml` + проверка), CI с `coverage: xdebug` и
  `XDEBUG_MODE=coverage`.
- **CI**: четыре job с четырьмя `composer install` и ключом кэша `hashFiles('composer.lock')` (файла в репозитории нет,
  ключ всегда был пустым) заменены одним `quality`-job; кэш по `composer.json`, триггеры расширены на push в `dev`,
  чтобы дрейф ловился на самой ветке, а не только на `main` и PR.
- **`phpstan.neon`**: `reportUnmatchedIgnoredErrors: false` (в CI Filament/Livewire/Larastan новее, часть хелперных
  ошибок не возникает и незакрытая запись роняет анализ при зелёном коде) и `tmpDir` в `.phpstan-cache`, который
  добавлен в `.gitignore`.
- **Формат релизов и фактическая версия**: constraint `geekcodev/laravel-max-client: ^1.1.0` разрешает и 1.1, и 1.2, а
  плагин рассчитан на 1.2 — поэтому в `AGENTS.md` §1 и README проверка версии переведена на факт (`composer show`), а не
  на цифру в constraint. Сам constraint намеренно не поднят: перевод ещё не слит.

## Тесты

Новый PHP-код: `scripts/check-coverage.php`. Фактическое покрытие не измерено — на текущем состоянии `dev` тесты
красные, гейт покрытия включается проверкой после слияния (пункт 2 плана).

## Нюансы

- **Красный Gate на `dev`** (проверено в контейнере, `php -m` → Xdebug в режиме `develop`): `composer lint` — 0 файлов;
  `composer analyse` — 81 ошибка (70 — `Access to an undefined property MaxChat::$id`, 6 в `ChatProfileRefresher`, 5 в
  `MaxMessageService`); `composer test` — 207 тестов, 97 errors, 12 failures, 13 risky. Причина: адаптация к реестру
  `laravel-max-client` 1.2 сделана в ветке `feat/max-chat-registry-v1-2` (`dcc7a48`, `b64d47e`, `4ab6783`) и в `dev` не
  слита, а `composer.lock` не коммитится, поэтому `composer install` подтянул 1.2.0. Зафиксировано в плане, пункт 1; код
  намеренно не чинился — это отдельная задача.
- `composer security-audit` из контейнера падал с `curl error 28` (packagist недоступен), помог
  `COMPOSER_IPRESOLVE=4` — gotcha 11 дополнен этим случаем.
- Придуманная команда `composer show pkg1 pkg2` не работает: `composer show` принимает один пакет. В `AGENTS.md` §1
  исправлено на два отдельных вызова. Новый CI проверен разбором YAML: единственный job `quality`, триггеры `push` и
  `pull_request`; `composer validate` — `composer.json` валиден (расхождение с локальным `composer.lock` существовало
  до сессии и на код не влияет: скрипты в content-hash не входят).
- **Ошибка в моём CI, пойманная пользователем в IDE**: в job `quality` оказалось два ключа `name:` — описание job и
  `PHP ${{ matrix.php }}` из матрицы. GitHub Actions такую ошибку не принимает, а `yaml.safe_load` прошёл молча (PyYAML
  дубликаты перезатирает). Лишний `name:` убран, оставлено имя из матрицы — как в `filament-max-users`; ключ кэша
  дополнен префиксом `${{ runner.os }}`, как в `laravel-max-client`. Проверка переделана на строгий загрузчик с
  поиском дубликатов, gotcha 19 добавлен в `AGENTS.md`.
- В `phpstan.neon` помимо хелперов из `tests/` есть живая запись `ignoreErrors` про `view()` в
  `src/Livewire/OperatorChat.php` — подавление production-ошибки в обход gotcha 15. Не исправлялось (ветка 1.2 не
  слита), но комментарий в конфиге приведён в соответствие с фактом, а закрытие вынесено отдельным пунктом в план.
- Xdebug в dev-контейнере включён, но `xdebug.mode` остаётся `develop`, несмотря на `XDEBUG_MODE=coverage` в
  `docker-compose.yml`: для гейта покрытия режим нужно задавать явно (`XDEBUG_MODE=coverage docker compose run ...`),
  иначе `composer coverage` не построит отчёт. Проверка — после слияния ветки 1.2.
- `.gitattributes` проверен: `git check-attr export-ignore` отдаёт `set` для `.agents/journals/JOURNAL.md`,
  `tests/TestCase.php`, `.github/workflows/ci.yml`, `phpunit.xml` и `unspecified` для `src/`. Ловушка: нужен паттерн
  `/.agents/**` — с завершающим слешем атрибут молча не выставляется.
- Из корня удалён `lw-debug2.log` (12 МБ, был untracked по `*.log`), добавлен `.phpstan-cache` в `.gitignore`.
- Текст release notes и плана 2026-09-03 перенесён без правок — правки в релизных описаниях задним числом не вносились,
  новый формат применяется со следующего релиза.
- По запросу «напиши краткий текст коммита» правило в `AGENTS.md` §2 уточнено: краткий текст = **только head**, одна
  строка, английский, Conventional Commits, по всем изменениям ветки; body — лишь когда просят отдельно. Формулировка «
  (+ body по стилю репозитория)» была размытой и в этой сессии привела к лишнему body.

## Gate

Прогнан на текущем состоянии `dev`: `composer lint` — 1 файл с правками (`scripts/check-coverage.php`, каталог `scripts`
добавлен в finder), после `composer format` — 0 файлов; `composer security-audit` — 0 уязвимостей (с
`COMPOSER_IPRESOLVE=4`, без флага сеть не отвечает); `composer analyse` — 81 ошибка, `composer test` — 207 тестов, 97
errors, 12 failures, 13 risky (причина в §Нюансы, задача в плане). `composer coverage` не запускался: покрытие
бессмысленно на красных тестах, а `scripts/check-coverage.php` проверен отдельно — на отсутствующем отчёте корректно
печатает подсказку и завершается с ненулевым кодом.
