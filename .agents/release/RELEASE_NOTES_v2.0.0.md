# v2.0.0

Синхронизация с ядром `geekcodev/max-php-client` **v2.0.0** и адаптером `geekcodev/laravel-max-client` **v2.0.0**.
Версия ломающая: события MAX приходят типизированными, вложения и кнопки — отдельными классами. Выпускается после
серверной проверки вебхуков в тестовом проекте; до неё это черновик.

## Новое

- `MaxMessageService` принимает конкретные подтипы `Update` вместо плоского события: `MessageCreatedUpdate`,
  `MessageEditedUpdate`, `MessageRemovedUpdate`, `DialogClearedUpdate`, `DialogRemovedUpdate`,
  `ChatTitleChangedUpdate`. Слушатель `HandleIncomingMaxUpdate` ветвится по `instanceof`.
- Вложения читаются типизированными классами ядра (`PhotoAttachment`, `VideoAttachment`, `AudioAttachment`,
  `FileAttachment`, `StickerAttachment`, `ContactAttachment`, `LocationAttachment`, `ShareAttachment`,
  `InlineKeyboardAttachment`) — метаданные карточки не изменились.
- Исходящие вложения собираются через `PhotoAttachmentRequest`, `VideoAttachmentRequest`, `AudioAttachmentRequest`,
  `FileAttachmentRequest` и `InlineKeyboardAttachmentRequest` с `Keyboard` и `RequestContactButton`.
- Номер телефона из контакта извлекается только из `vcf_info` (`vcfPhone` удалён в ядре v2.0.0).

## Изменение (BC)

- Constraints: `geekcodev/max-php-client` `^1.1.11` → `^2.0`, `geekcodev/laravel-max-client` `^1.2.3` → `^2.0`.
- Публичные методы `MaxMessageService` принимают типизированные подтипы `Update`. Код приложения, передававший плоский
  `Update`, требует правок.
- `MessageBody::$caption` больше нет: правка сообщения обновляет только текст.
- Видео не скачивается на приватный диск: карточка показывает тип вложения, файл остаётся в MAX.
- Невозможные в типизированной модели сценарии удалены из тестов: lifecycle-апдейт без `chat_id`/`user` и вложение с
  пустым `payload` (такие payload отклоняются разбором ядра).

## Затронутые сценарии

- **История переписки**: создание, правка, удаление сообщения, очистка и удаление диалога, смена названия — поведение
  прежнее, изменился только способ ветвления.
- **Ответ оператора файлом или картинкой**: загрузка через `uploadMedia` возвращает `UploadEndpoint`; для картинки
  используется `token`/`url`, для остальных типов — `token` (`UploadedInfo`).
- **Кнопка «Поделиться контактом»**: клавиатура собирается типизированными классами, текст-заглушка сохранена.
- **Карточка контакта**: имя из `max_info`/vCard, телефон из `vcf_info`; неподписанный контакт по-прежнему не
  подтверждается.

## Качество

Lint 0/60, PHPStan level max 0, PHPUnit 371/371 (951 assertion), покрытие 97.57% строк (1405/1440), `composer audit` 0.
Серверная проверка вебхуков в тестовом проекте — обязательное условие выпуска.
