# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] - 2026-04-27

### EN

#### Release summary

This release updates `maxoxide-php` for the current public MAX REST API, adds convenience helpers for media sending, makes update parsing more forward-compatible, and expands the dispatcher into a more practical routing layer.

#### Breaking changes

- `Update::$timestamp` is now nullable because unknown future updates may omit a timestamp.
- Use `Update::timestampOrDefault()` when the previous `0` fallback behavior is desired.
- Plain text should be represented by leaving `NewMessageBody::$format` unset. `MessageFormat` now exposes only documented MAX format values: `MARKDOWN` and `HTML`.
- Code that constructs raw `Button` objects for `open_app` should migrate to `Button::openApp()`, `Button::openAppWithPayload()`, or `Button::openAppFull()`, which serialize the official MAX wire model with `web_app`, optional `payload`, and optional `contact_id`.

`User::$name` and `ChatMember::$name` remain available as legacy aliases in PHP, but new code should prefer `firstName`, `lastName`, and `displayName()`.

#### Added

- Added typed fallback support for unknown `Update` and unknown attachments, preserving raw JSON for later inspection.
- Added attachment deserialization for both wrapped `payload` objects and flat attachment objects, so `Button::requestGeoLocation()` updates can deserialize as `AttachmentKind::LOCATION` with `latitude` and `longitude`.
- Added typed string value classes:
  - `ChatType`
  - `ChatStatus`
  - `MessageFormat`
  - `ButtonIntent`
  - `LinkType`
  - `AttachmentKind`
  - `ChatAdminPermission`
  - `SenderAction`
- Added more complete MAX models for users, chats, members, admins, video metadata, image/photo payloads, upload endpoints, upload responses, and webhook subscriptions.
- Added current MAX profile fields to `User` and `ChatMember`:
  - `firstName`
  - `lastName`
  - `username`
  - `description`
  - `avatarUrl`
  - `fullAvatarUrl`
  - `commands`, where available
- Added `Button::openApp()`, `Button::openAppWithPayload()`, and `Button::openAppFull()` using the official `web_app`, `payload`, and `contact_id` fields.
- Added `Button::clipboard()`.
- Added builders and helpers for outgoing messages and attachments:
  - `NewMessageBody::empty()`
  - `NewMessageBody::textOpt()`
  - `NewMessageBody::withAttachments()`
  - `NewMessageBody::withReplyTo()`
  - `NewMessageBody::withForwardFrom()`
  - `NewMessageBody::withNotify()`
  - `NewAttachment::imageUrl()`
  - `NewAttachment::imagePhotos()`
  - `NewAttachment::imagePayload()`
- Added `SendMessageOptions` with `disable_link_preview`.
- Added message, video, member, and admin endpoints:
  - `sendMessageToChatWithOptions()`
  - `sendMessageToUserWithOptions()`
  - `getMessagesByIds()`
  - `getVideo()`
  - `getMembersByIds()`
  - `addAdmins()`
  - `removeAdmin()`
- Added typed sender action methods:
  - `sendSenderAction()`
  - `sendTypingOn()`
  - `sendSendingImage()`
  - `sendSendingVideo()`
  - `sendSendingAudio()`
  - `sendSendingFile()`
  - `markSeen()`
- Added upload-and-send helpers for both chat and user recipients:
  - `sendImageToChat()` / `sendImageToUser()`
  - `sendVideoToChat()` / `sendVideoToUser()`
  - `sendAudioToChat()` / `sendAudioToUser()`
  - `sendFileToChat()` / `sendFileToUser()`
  - byte-based variants for the same media types
- Added `Bot::getUpdatesRaw()` and `RawUpdatesResponse` for raw polling before typed parsing.
- Added `Dispatcher::onUpdate()`, composable `Filter` values, regex text filters, media/file attachment filters, `onStart()`, `task()`, `onRawUpdate()`, and raw dispatch via `dispatchRaw()`.
- Added `examples/media_bot.php` and `examples/dispatcher_filters_bot.php`.

#### Changed

- `getUploadUrl()` now returns an `UploadEndpoint` object and serializes upload types using documented lowercase wire values.
- Long polling now receives raw update JSON first, then dispatches through raw and typed handlers.
- Webhook handling now dispatches raw JSON through the same dispatcher path as long polling.
- Upload helpers now accept attachment tokens from either the upload endpoint response or multipart upload response, preserve the MAX `photos` token map for image send helpers, and retry briefly while MAX reports an uploaded attachment as not processed yet.
- `uploadFile()` and `uploadBytes()` still return a simple token string for existing callers, but image uploads can now also be represented as `ImageAttachmentPayload` when using send helpers.
- README examples now use the newer builders, dispatcher filters, typed sender actions, and media helpers.
- `examples/live_api_test.php` now covers the expanded PHP API, including raw updates, message options, media helpers, `getVideo()`, member/admin helpers, typed sender actions, `open_app`, and `clipboard`.

#### Fixed

- Fixed Composer autoloading for aggregate source files such as `src/Types.php` and `src/Dispatcher.php`, so `vendor/autoload.php` now loads classes like `Maxoxide\User`, `Maxoxide\Update`, and `Maxoxide\Filter` without requiring `bootstrap.php`.
- `bootstrap.php` now uses `require_once`, so it remains compatible with Composer autoload and older direct source-tree workflows.
- Unknown or malformed attachments no longer break entire message/update parsing.
- Request-location payloads can now be parsed when MAX sends a flat attachment object.

#### MAX platform gaps documented by live testing

- `Button::requestContact()` sends successfully, but incoming contact attachments may contain empty `contact_id` and `vcf_phone`.
- `Button::requestGeoLocation()` may arrive either as a structured `location` attachment or as a client map-card/link fallback.
- `sendSenderAction($chatId, SenderAction::TYPING_ON)` returns success from the API, but the visible typing indicator is not reliably confirmed in the client.
- `setMyCommands()` remains experimental: live `POST /me/commands` requests return `404`, and the public MAX REST docs do not currently expose a documented write endpoint for command menu updates.

#### Verification

- `composer dump-autoload`
- `php -r 'require "vendor/autoload.php"; var_dump(class_exists("Maxoxide\\User"), class_exists("Maxoxide\\Update"), class_exists("Maxoxide\\Filter"));'`
- `find src tests examples -name '*.php' -exec php -l {} \;`
- `vendor/bin/phpunit tests`
- `vendor/bin/phpunit --bootstrap bootstrap.php tests`

### RU

#### Кратко о релизе

Этот релиз обновляет `maxoxide-php` под текущий публичный REST API MAX, добавляет вспомогательные методы для отправки медиа, делает разбор обновлений устойчивее к будущим типам MAX и расширяет `Dispatcher` до более практичного роутинга.

#### Ломающие изменения

- `Update::$timestamp` теперь nullable, потому что неизвестные будущие update могут не содержать timestamp.
- Для старого поведения с резервным значением `0` используйте `Update::timestampOrDefault()`.
- Обычный текст должен задаваться отсутствием `NewMessageBody::$format`. `MessageFormat` теперь содержит только документированные MAX-значения: `MARKDOWN` и `HTML`.
- Код, который вручную собирал сырые `Button`-объекты для `open_app`, стоит перевести на `Button::openApp()`, `Button::openAppWithPayload()` или `Button::openAppFull()`: они сериализуют официальную MAX wire-модель с `web_app`, опциональным `payload` и опциональным `contact_id`.

`User::$name` и `ChatMember::$name` в PHP оставлены как legacy aliases, но новый код лучше писать через `firstName`, `lastName` и `displayName()`.

#### Добавлено

- Добавлен резервный разбор неизвестных `Update` и неизвестных вложений с сохранением raw JSON.
- Добавлен разбор вложений как в форме с обёрнутым `payload`, так и в плоской форме объекта вложения, поэтому updates от `Button::requestGeoLocation()` могут десериализоваться как `AttachmentKind::LOCATION` с `latitude` и `longitude`.
- Добавлены типизированные классы строковых значений:
  - `ChatType`
  - `ChatStatus`
  - `MessageFormat`
  - `ButtonIntent`
  - `LinkType`
  - `AttachmentKind`
  - `ChatAdminPermission`
  - `SenderAction`
- Расширены модели MAX для пользователей, чатов, участников, администраторов, video metadata, image/photo payloads, upload endpoints, upload responses и webhook subscriptions.
- В `User` и `ChatMember` добавлены актуальные поля профиля MAX:
  - `firstName`
  - `lastName`
  - `username`
  - `description`
  - `avatarUrl`
  - `fullAvatarUrl`
  - `commands`, где они доступны
- Добавлены `Button::openApp()`, `Button::openAppWithPayload()` и `Button::openAppFull()` с официальными полями `web_app`, `payload`, `contact_id`.
- Добавлен `Button::clipboard()`.
- Добавлены builders и вспомогательные методы для исходящих сообщений и вложений:
  - `NewMessageBody::empty()`
  - `NewMessageBody::textOpt()`
  - `NewMessageBody::withAttachments()`
  - `NewMessageBody::withReplyTo()`
  - `NewMessageBody::withForwardFrom()`
  - `NewMessageBody::withNotify()`
  - `NewAttachment::imageUrl()`
  - `NewAttachment::imagePhotos()`
  - `NewAttachment::imagePayload()`
- Добавлен `SendMessageOptions` с `disable_link_preview`.
- Добавлены методы для сообщений, видео, участников и администраторов:
  - `sendMessageToChatWithOptions()`
  - `sendMessageToUserWithOptions()`
  - `getMessagesByIds()`
  - `getVideo()`
  - `getMembersByIds()`
  - `addAdmins()`
  - `removeAdmin()`
- Добавлены типизированные действия отправителя:
  - `sendSenderAction()`
  - `sendTypingOn()`
  - `sendSendingImage()`
  - `sendSendingVideo()`
  - `sendSendingAudio()`
  - `sendSendingFile()`
  - `markSeen()`
- Добавлены helpers загрузки и отправки для chat/user адресатов:
  - `sendImageToChat()` / `sendImageToUser()`
  - `sendVideoToChat()` / `sendVideoToUser()`
  - `sendAudioToChat()` / `sendAudioToUser()`
  - `sendFileToChat()` / `sendFileToUser()`
  - варианты для байтов тех же типов медиа
- Добавлены `Bot::getUpdatesRaw()` и `RawUpdatesResponse` для raw polling до typed parsing.
- Добавлены `Dispatcher::onUpdate()`, составные `Filter`, regex-фильтры текста, фильтры media/file вложений, `onStart()`, `task()`, `onRawUpdate()` и raw dispatch через `dispatchRaw()`.
- Добавлены `examples/media_bot.php` и `examples/dispatcher_filters_bot.php`.

#### Изменено

- `getUploadUrl()` теперь возвращает объект `UploadEndpoint` и сериализует типы загрузки документированными lowercase wire-значениями.
- Long polling сначала получает raw JSON update, затем диспетчеризация проходит через raw и typed handlers.
- Webhook теперь передаёт raw JSON тем же путём диспетчера, что и long polling.
- Вспомогательные методы загрузки принимают attachment token как из ответа upload endpoint, так и из multipart upload response, сохраняют MAX `photos` token map для image send helpers и коротко ретраят отправку, пока MAX сообщает, что вложение ещё не обработано.
- `uploadFile()` и `uploadBytes()` по-прежнему возвращают простую строку token для существующего кода, но image uploads теперь могут быть представлены как `ImageAttachmentPayload` при использовании send helpers.
- Примеры README переведены на новые builders, фильтры Dispatcher, typed sender actions и media helpers.
- `examples/live_api_test.php` теперь покрывает расширенный PHP API: raw updates, message options, media helpers, `getVideo()`, member/admin helpers, typed sender actions, `open_app` и `clipboard`.

#### Исправлено

- Исправлена Composer-автозагрузка агрегированных файлов вроде `src/Types.php` и `src/Dispatcher.php`: `vendor/autoload.php` теперь подгружает классы `Maxoxide\User`, `Maxoxide\Update`, `Maxoxide\Filter` без ручного `bootstrap.php`.
- `bootstrap.php` теперь использует `require_once`, поэтому остаётся совместимым с Composer autoload и старым запуском напрямую из дерева исходников.
- Неизвестные или некорректные attachments больше не ломают разбор всего message/update.
- `request_geo_location` payloads теперь разбираются и в случае, когда MAX присылает плоский объект вложения.

#### Ограничения платформы MAX, выявленные live-тестами

- `Button::requestContact()` отправляется успешно, но входящие contact-вложения могут содержать пустые `contact_id` и `vcf_phone`.
- `Button::requestGeoLocation()` может прийти как структурированное `location`-вложение или как клиентский fallback в виде map card/link.
- `sendSenderAction($chatId, SenderAction::TYPING_ON)` получает успешный ответ API, но видимый индикатор набора текста в клиенте не подтверждён стабильно.
- `setMyCommands()` остаётся экспериментальным helper: live-запросы `POST /me/commands` возвращают `404`, а публичный REST MAX сейчас не показывает документированного write-эндпоинта для меню команд.

#### Проверка

- `composer dump-autoload`
- `php -r 'require "vendor/autoload.php"; var_dump(class_exists("Maxoxide\\User"), class_exists("Maxoxide\\Update"), class_exists("Maxoxide\\Filter"));'`
- `find src tests examples -name '*.php' -exec php -l {} \;`
- `vendor/bin/phpunit tests`
- `vendor/bin/phpunit --bootstrap bootstrap.php tests`

## [1.0.0] - 2026-03-27

### EN

#### Release summary

This release establishes the first stable `maxoxide-php` API, adds a real interactive live API test harness for MAX, and makes message delivery APIs explicit about whether they target a `chat_id` or a global `user_id`.

#### API baseline

- The stable PHP API starts with explicit recipient-specific methods:
  - `sendTextToChat($chatId, $text)`
  - `sendTextToUser($userId, $text)`
  - `sendMarkdownToChat($chatId, $text)`
  - `sendMarkdownToUser($userId, $text)`
  - `sendMessageToChat($chatId, $body)`
  - `sendMessageToUser($userId, $body)`
- Use `*_ToChat(...)` methods when you know the dialog, group, or channel `chat_id`.
- Use `*_ToUser(...)` methods when you only know the global MAX `user_id`.

#### Added

- Added the core synchronous cURL client `Bot`.
- Added the main MAX API methods for bot profile, messages, chats, members, webhooks, long polling, uploads, and experimental command menu updates.
- Added core model classes in `Types.php`: users, chats, members, messages, attachments, keyboards, callbacks, webhook subscriptions, upload types, and simple API results.
- Added `Update`, `Callback`, and `UpdatesResponse` parsing for known MAX update types.
- Added `Dispatcher` with typed handlers:
  - `on()`
  - `onMessage()`
  - `onEditedMessage()`
  - `onCallback()`
  - `onCallbackPayload()`
  - `onBotStarted()`
  - `onBotAdded()`
  - `onCommand()`
  - `onFilter()`
  - `onError()`
- Added `WebhookReceiver` without a framework dependency.
- Added examples:
  - `examples/echo_bot.php`
  - `examples/keyboard_bot.php`
  - `examples/webhook_bot.php`
  - `examples/live_api_test.php`
- Added `examples/live_api_test.php`, an interactive real-API harness with:
  - English and Russian language selection
  - runtime input for token, bot URL, webhook settings, file path, delays, and timeouts
  - manual tester-driven steps in the MAX client
  - optional group-chat phase
  - `PASS / FAIL / SKIP` summary
- Added tests for type parsing, serialization, dispatcher behavior, and message dispatch query construction.

#### Changed

- Documentation now clearly distinguishes:
  - `user_id` as the global MAX user identifier
  - `chat_id` as the identifier of a concrete dialog, group, or channel
- README and README.ru list chat-targeted and user-targeted methods side by side.
- Examples use only explicit `*_ToChat()` and `*_ToUser()` APIs.

#### Fixed

- `answerCallback()` sends `callback_id` as a query parameter, matching the real MAX API.
- `editMessage()` returns `SimpleResult` instead of incorrectly deserializing a message body.
- Attachment parsing is tolerant enough for malformed or unknown incoming attachments to avoid breaking the whole update/message in common live scenarios.
- Action handling and live testing use the real MAX action value `typing_on`.

#### MAX platform gaps documented by live testing

- `request_contact` is documented by MAX, but live tests observed contact attachments with empty `contact_id` and `vcf_phone`.
- `request_geo_location` is documented by MAX, and the mobile client shows a sent location card, but early live polling tests did not reliably receive a matching structured update.
- `typing_on` returns a successful API response, but the client-side typing indicator was not reliably visible in live testing.
- `setMyCommands()` remains experimental: live `POST /me/commands` requests returned `404`, and the public MAX REST docs did not expose a documented write endpoint for command menu updates.

#### Verification

- `composer install`
- `vendor/bin/phpunit tests`
- The live API harness was used against a real MAX bot during the release cycle.

### RU

#### Кратко о релизе

Этот релиз задаёт первый стабильный API `maxoxide-php`, добавляет полноценный интерактивный live-тест на реальном API MAX и делает API отправки сообщений явным по типу получателя: `chat_id` или глобальный `user_id`.

#### Базовый API

- Стабильный PHP API начинается с явных методов по типу адресата:
  - `sendTextToChat($chatId, $text)`
  - `sendTextToUser($userId, $text)`
  - `sendMarkdownToChat($chatId, $text)`
  - `sendMarkdownToUser($userId, $text)`
  - `sendMessageToChat($chatId, $body)`
  - `sendMessageToUser($userId, $body)`
- Используйте методы `*_ToChat(...)`, когда известен `chat_id` диалога, группы или канала.
- Используйте методы `*_ToUser(...)`, когда известен только глобальный MAX `user_id`.

#### Добавлено

- Добавлен основной синхронный cURL-клиент `Bot`.
- Добавлены основные методы MAX API для профиля бота, сообщений, чатов, участников, вебхуков, long polling, загрузок и экспериментального меню команд.
- Добавлены основные модели в `Types.php`: пользователи, чаты, участники, сообщения, вложения, клавиатуры, callbacks, webhook subscriptions, upload types и simple API results.
- Добавлены `Update`, `Callback` и `UpdatesResponse` для разбора известных типов MAX update.
- Добавлен `Dispatcher` с typed handlers:
  - `on()`
  - `onMessage()`
  - `onEditedMessage()`
  - `onCallback()`
  - `onCallbackPayload()`
  - `onBotStarted()`
  - `onBotAdded()`
  - `onCommand()`
  - `onFilter()`
  - `onError()`
- Добавлен `WebhookReceiver` без зависимости от фреймворка.
- Добавлены примеры:
  - `examples/echo_bot.php`
  - `examples/keyboard_bot.php`
  - `examples/webhook_bot.php`
  - `examples/live_api_test.php`
- Добавлен `examples/live_api_test.php` — интерактивный harness для проверки реального API, который включает:
  - выбор языка English / Russian
  - ввод токена, URL бота, webhook-настроек, пути к файлу, задержек и таймаутов во время старта
  - ручные шаги тестера в клиенте MAX
  - необязательный этап группового чата
  - итоговую сводку `PASS / FAIL / SKIP`
- Добавлены тесты для разбора типов, сериализации, поведения dispatcher и построения query при отправке сообщений.

#### Изменено

- В документации явно зафиксировано:
  - `user_id` — глобальный идентификатор пользователя MAX
  - `chat_id` — идентификатор конкретного диалога, группы или канала
- README и README.ru перечисляют методы для chat/user адресатов рядом.
- Примеры используют только явные API `*_ToChat()` и `*_ToUser()`.

#### Исправлено

- `answerCallback()` отправляет `callback_id` query-параметром, как требует реальный MAX API.
- `editMessage()` возвращает `SimpleResult`, а не пытается неверно десериализовать тело сообщения.
- Разбор вложений достаточно устойчив к некорректным или неизвестным входящим attachments, чтобы в типичных live-сценариях не ломать весь update/message.
- Для действий бота и live-теста закреплено реальное значение MAX `typing_on`.

#### Ограничения платформы MAX, выявленные live-тестами

- `request_contact` задокументирован в MAX, но live-тесты наблюдали contact-вложения с пустыми `contact_id` и `vcf_phone`.
- `request_geo_location` задокументирован в MAX, мобильный клиент показывает отправленную карточку геопозиции, но ранние live polling-тесты не получали соответствующий структурированный update стабильно.
- `typing_on` возвращает успешный API-ответ, но видимый индикатор набора текста в клиенте live-тестами не подтверждён стабильно.
- `setMyCommands()` остаётся экспериментальным helper: live-запросы `POST /me/commands` возвращали `404`, а публичный REST MAX не показывал документированного write-эндпоинта для меню команд.

#### Проверка

- `composer install`
- `vendor/bin/phpunit tests`
- Live API harness использовался против реального MAX-бота в рамках релизного цикла.
