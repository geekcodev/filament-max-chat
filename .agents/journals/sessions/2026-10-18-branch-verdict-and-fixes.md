---
tags: [ review, audit, verdict, reconcile, broadcast, attachments, preview-text, docs, gate ]
date: 2026-10-18
---

# Итоговый вердикт ветки и правка замечаний

## Проблема

Нужен был итоговый вердикт по всем изменениям ветки `dev` относительно стабильной линии (`origin/main` = тег `v1.1.5`):
два коммита (`560cd60` поиск, `cb454ed` автообработка апдейтов) плюс незакоммиченная партия «вложения всех девяти
типов». Вердикт — на соответствие `AGENTS.md`, best practices и production-grade. Ревью нашло шесть замечаний, которые
нужно было исправить.

## Решение

Вердикт: изменения соответствуют `AGENTS.md` (§5 контракты, §8 OWASP, §10 gotchas, §11 чек-лист), best practices и
production-grade; блокеров нет. Исправлены замечания:

- **F1** — `OperatorChat::handleChatRemoved()`: docblock обещал закрытие ленты и при удалении чата другим оператором, но
  `MaxMessageService::removeChat()` (операторское действие) `MaxChatRemoved` не эмитит — broadcast есть только у
  MAX-стороны (`applyIncomingChatRemoval()`). Решение: привести docblock к фактическому поведению и явно записать, что
  удаление чата оператором другие вкладки увидят следующим `wire:poll`. Эмитировать событие из `removeChat()` не стали:
  своё удаление закрывает ленту локально, а broadcast вызвал бы второй пересчёт счётчика у того же оператора (это же
  объяснение уже стояло в комментарии рядом).
- **F2** — `MaxMessage::previewText()`: чтение первого вложения шло по типизированному геттеру, поэтому вложение без
  ключа `type` (метаданные записаны в обход стора) уронило бы метод. Теперь сырое значение колонки читается как `mixed`
  через `getAttribute()`, форма проверяется (`is_array` + строковый `type`), иначе подпись «файл». Именно `mixed`, а не
  защита «поверх типа»: PHPStan level max считает phpdoc-типы достоверными (`arrayValues.list`,
  `nullCoalesce.offset`), поэтому проверка типизированного значения не проходит статику.
- **F3** — комментарий к SQLite-шиммеру `lower()` в `tests/TestCase.php`: поясняет, что шим эмулирует Unicode-aware
  `lower()` MySQL/PostgreSQL, иначе читатель решит, что SQL исполняется нативным SQLite (он ASCII-only).
- **F4** — ограничение «удаление чата оператором не broadcast'ится» зафиксировано в плане (`PLAN-filament-max-chat.md`,
  раздел 1.10) и в docblock из F1.
- **F5** — `FilamentMaxChatServiceProvider`: убрано избыточное `=== false` при вызове `config()->boolean()`.
- **F6** — `OperatorChat::reconcileMessages()`: сверка ленты по `wire:poll` теперь учитывает и метаданные вложений, а не
  только текст, поэтому правка без нового текста (замена или удаление вложения) доезжает следующим тиком при отсутствии
  broadcast.

## Тесты

Добавлено три теста:

- `OperatorChatTest::test_refresh_reloads_feed_when_message_attachment_changed_without_broadcast` — смена вложения без
  смены текста перечитывает ленту по `refresh()`;
- `MaxMessageTest::test_preview_text_with_attachment_without_type_falls_back_to_file` — вложение без `type`;
- `MaxMessageTest::test_preview_text_with_non_list_attachment_does_not_fail` — метаданные не списком, а картой.

Метаданные в обход стора в unit-тестах создаются напрямую через `MaxMessage::query()->create()`, минуя типизированный
хелпер `createMessage()`: его phpdoc (`StoredAttachment|list<StoredAttachment>|null`) намеренно не пропускает невалидную
форму, а тестировать нужно именно её.

## Нюансы

Топология ветки нелинейна: `merge-base(HEAD, origin/main) = ad0119c`, при этом `dev` = содержимое `origin/main` плюс два
коммита (проверено симметричным диффом обеих сторон, 28 файлов). Это нормальный результат merge-процесса PR, а не
переписанная история.

## Gate

Локально на текущем `vendor/`:

- `composer lint` — 0 из 54; `composer format` — 0 правок;
- `composer analyse` (PHPStan level max) — 0 ошибок;
- `composer test` / `composer coverage` — 346 тестов, 864 assertion, покрытие 97.41% строк (1204/1236), порог 95%
  пройден;
- `composer security-audit` — недоступен: нет сети до `repo.packagist.org` (`curl error 28`, код 100), в том числе с
  `COMPOSER_IPRESOLVE=4`; это отсутствие сети, а не результат аудита. В прошлых сессиях аудит был чист.

Шаг 7 Gate (`.ci-sim/`, чистый резолв) в этой сессии не перепрогонялся; ранее для этой партии был зелёным (Laravel
13.37.0, Filament 5.10.2, Livewire 4.4.7, Larastan 3.13.0).

Коммиты, теги и push не создавались.
