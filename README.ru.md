[![Packagist](https://img.shields.io/packagist/v/mammothcoding/maxoxide.svg)](https://packagist.org/packages/mammothcoding/maxoxide)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![MIT](https://img.shields.io/badge/license-MIT-blue.svg)](https://choosealicense.com/licenses/mit/)
[![Build Status](https://github.com/mammothcoding/maxoxide_php/actions/workflows/php.yml/badge.svg?event=push)](https://github.com/mammothcoding/maxoxide_php/actions/workflows/php.yml)

Readme на разных языках:
[EN](README.md) · [RU](README.ru.md)

# maxoxide-php

Синхронная PHP-библиотека для создания ботов на платформе [Max мессенджер](https://max.ru).
Вдохновлена Rust-библиотекой [maxoxide](https://github.com/mammothcoding/maxoxide).

Требует PHP 7.4+, расширения `curl` и `json`. Никаких внешних зависимостей в runtime.
PHP-версия синхронизирована с Rust `maxoxide` 2.0.0: актуальные поля профиля MAX, raw fallback для новых update, расширенные фильтры диспетчера, typed sender actions, media helpers, кнопки `open_app`/`clipboard` и image `photos` payload при загрузках.

---

## Установка

```bash
composer require mammothcoding/maxoxide
```

---

## Быстрый старт

```php
<?php
require 'vendor/autoload.php';

use Maxoxide\Bot;
use Maxoxide\Context;
use Maxoxide\Dispatcher;

$bot = Bot::fromEnv();   // читает MAX_BOT_TOKEN из окружения
$dp  = new Dispatcher($bot);

$dp->onCommand('/start', function (Context $ctx) {
    if ($ctx->update->message !== null) {
        $ctx->bot->sendMarkdownToChat(
            $ctx->update->message->chatId(),
            'Привет!'
        );
    }
});

$dp->onMessage(function (Context $ctx) {
    if ($ctx->update->message !== null) {
        $text = $ctx->update->message->text() ?? '(без текста)';
        $ctx->bot->sendTextToChat($ctx->update->message->chatId(), $text);
    }
});

$dp->startPolling();
```

```bash
MAX_BOT_TOKEN=your_token php examples/echo_bot.php
```

---

## Структура проекта

```text
maxoxide-php/
├── composer.json               -- зависимости и автозагрузка (PSR-4)
├── README.md
├── README.ru.md
├── bootstrap.php              -- ручной bootstrap для запуска примеров из исходников
├── src/
│   ├── MaxException.php       -- единственный класс ошибок
│   ├── Types.php              -- все типы данных: User, Chat, Message, Button, Keyboard, ...
│   ├── Update.php             -- Update, Callback, UpdatesResponse, RawUpdatesResponse
│   ├── Bot.php                -- HTTP-клиент на cURL, все методы API, загрузка файлов
│   ├── Dispatcher.php         -- Dispatcher, Context, фильтры, long polling
│   └── Webhook.php            -- WebhookReceiver без зависимости от фреймворка
├── examples/
│   ├── echo_bot.php           -- эхо-бот через long polling
│   ├── keyboard_bot.php       -- inline-клавиатура и callback-кнопки
│   ├── dispatcher_filters_bot.php -- составные фильтры, raw hooks, tasks
│   ├── media_bot.php          -- upload-and-send helpers для медиа/файлов
│   ├── webhook_bot.php        -- пример webhook-приёмника
│   └── live_api_test.php      -- интерактивный harness против реального MAX API
└── tests/
    ├── TypesTest.php          -- юнит-тесты типов, фильтров, сериализации
    └── BotSendMessageTest.php -- регрессионные тесты для POST /messages
```

---

## Методы API

| Метод | Описание |
|-------|----------|
| `getMe()` | Информация о боте |
| `sendTextToChat(chatId, text)` | Текст в диалог/группу/канал по `chatId` |
| `sendTextToUser(userId, text)` | Текст пользователю по глобальному MAX `userId` |
| `sendMarkdownToChat(chatId, text)` | Markdown в диалог/группу/канал |
| `sendMarkdownToUser(userId, text)` | Markdown пользователю по `userId` |
| `sendMessageToChat(chatId, body)` | Сообщение с вложениями или кнопками по `chatId` |
| `sendMessageToChatWithOptions(chatId, body, options)` | Отправка с query-настройками, например `disable_link_preview` |
| `sendMessageToUser(userId, body)` | Сообщение с вложениями или кнопками по `userId` |
| `sendMessageToUserWithOptions(userId, body, options)` | Сообщение пользователю с query-настройками |
| `editMessage(mid, body)` | Редактировать сообщение |
| `deleteMessage(mid)` | Удалить сообщение |
| `getMessage(mid)` | Получить сообщение по ID |
| `getMessages(chatId, ...)` | Список сообщений чата |
| `getMessagesByIds(ids, ...)` | Получить одно или несколько сообщений по ID |
| `getVideo(videoToken)` | Метаданные загруженного видео и playback URLs |
| `answerCallback(body)` | Ответ на нажатие inline-кнопки |
| `getChats(...)` | Список групповых чатов |
| `getChat(chatId)` | Информация о чате |
| `editChat(chatId, body)` | Изменить название или описание |
| `deleteChat(chatId)` | Удалить чат |
| `sendAction(chatId, action)` | Индикатор набора текста и другие действия |
| `sendSenderAction(chatId, action)` | Отправить типизированное действие отправителя |
| `sendTypingOn(chatId)` / `markSeen(chatId)` | Удобные sender actions |
| `sendSendingImage/Video/Audio/File(chatId)` | Индикаторы отправки медиа/файлов |
| `getPinnedMessage(chatId)` | Закреплённое сообщение |
| `pinMessage(chatId, body)` | Закрепить сообщение |
| `unpinMessage(chatId)` | Открепить |
| `getMembers(chatId, ...)` | Участники чата |
| `getMembersByIds(chatId, userIds)` | Получить выбранных участников |
| `addMembers(chatId, userIds)` | Добавить участников |
| `removeMember(chatId, userId)` | Удалить участника |
| `getAdmins(chatId)` | Администраторы |
| `addAdmins(chatId, admins)` | Выдать права администратора |
| `removeAdmin(chatId, userId)` | Забрать права администратора |
| `getMyMembership(chatId)` | Членство бота в чате |
| `leaveChat(chatId)` | Выйти из чата |
| `getSubscriptions()` | Список подписок на вебхуки |
| `subscribe(body)` | Зарегистрировать вебхук |
| `unsubscribe(url)` | Удалить вебхук |
| `getUpdates(...)` | Разовый long-poll запрос |
| `getUpdatesRaw(...)` | Raw long-poll запрос до typed parsing |
| `getUploadUrl(type)` | Получить upload URL для типа вложения |
| `uploadFile(type, path, name, mime)` | Двухшаговая загрузка файла |
| `uploadBytes(type, bytes, name, mime)` | То же, из байт |
| `sendImage/Video/Audio/FileToChat(...)` | Загрузить локальный файл и отправить в чат |
| `sendImage/Video/Audio/FileToUser(...)` | Загрузить локальный файл и отправить пользователю |
| `sendImage/Video/Audio/FileBytesToChat(...)` | Загрузить байты и отправить в чат |
| `sendImage/Video/Audio/FileBytesToUser(...)` | Загрузить байты и отправить пользователю |
| `setMyCommands(commands)` | Экспериментально: MAX сейчас возвращает `404` |

---

## userId vs chatId

Эти два идентификатора разные:

- `userId` -- глобальный ID пользователя в MAX.
- `chatId` -- ID конкретного диалога, группы или канала.
- В личном чате `message->sender->userId` -- это пользователь, а `message->chatId()` -- конкретный диалог бота с ним.
- `sendTextToChat / sendMessageToChat` -- когда есть `chatId` диалога или группы.
- `sendTextToUser / sendMessageToUser` -- когда есть только глобальный `userId`.

`User` и `ChatMember` теперь содержат профильные поля MAX: `firstName`, `lastName`, `username`, `description`, `avatarUrl`, `fullAvatarUrl` и `commands`, где они доступны. Если нужна одна строка для отображения имени, используйте `displayName()`. Legacy alias `name` оставлен для существующего PHP-кода.

---

## Фильтры диспетчера

```php
use Maxoxide\AttachmentKind;
use Maxoxide\Filter;

$dp->onCommand('/start', $handler);          // конкретная команда
$dp->onMessage($handler);                    // любое новое сообщение
$dp->onEditedMessage($handler);              // редактирование
$dp->onCallback($handler);                   // любой callback
$dp->onCallbackPayload('btn:ok', $handler);  // конкретный payload
$dp->onBotStarted($handler);                 // первый запуск бота
$dp->onBotAdded($handler);                   // добавление в чат
$dp->onFilter(fn($u) => ..., $handler);      // произвольный предикат
$dp->on($handler);                           // каждое обновление

$dp->onUpdate(
    Filter::message()
        ->andFilter(Filter::chat($chatId))
        ->andFilter(Filter::textContains('ping')),
    $handler
);

$dp->onUpdate(Filter::hasAttachmentType(AttachmentKind::FILE), $handler);
$dp->onUpdate(Filter::hasMedia(), $handler);
$dp->onUpdate(Filter::unknownUpdate(), $handler);

$dp->onRawUpdate($handler);                  // raw JSON каждого update
$dp->onStart($handler);                      // один раз перед polling
$dp->task(300, $handler);                    // periodic task во время polling
```

Срабатывает первый подходящий хендлер. Более специфичные фильтры регистрируйте раньше.
Raw handlers всегда запускаются перед typed handlers. Неизвестные будущие update сохраняются как `Update` с доступным `raw()`.

---

## Inline-клавиатура

```php
use Maxoxide\Button;
use Maxoxide\KeyboardPayload;
use Maxoxide\NewMessageBody;

$keyboard = new KeyboardPayload([
    [
        Button::callback('Да', 'answer:yes'),
        Button::callback('Нет', 'answer:no'),
    ],
    [
        Button::link('Сайт', 'https://max.ru'),
        Button::clipboard('Скопировать код', 'promo-123'),
    ],
    [
        Button::requestContact('Поделиться контактом'),
        Button::requestGeoLocation('Поделиться геопозицией'),
    ],
]);

$body = NewMessageBody::text('Вы уверены?')->withKeyboard($keyboard);
$bot->sendMessageToChat($chatId, $body);
```

`Button::openAppFull($text, $webApp, $payload, $contactId)` сериализует официальную MAX wire-модель `open_app` с `web_app`, optional `payload` и optional `contact_id`.

---

## Загрузка файлов

MAX использует двухшаговый процесс. `uploadFile` и `uploadBytes` возвращают готовый attachment token:

```php
use Maxoxide\NewAttachment;
use Maxoxide\NewMessageBody;
use Maxoxide\UploadType;

$token = $bot->uploadFile(UploadType::IMAGE, './photo.jpg', 'photo.jpg', 'image/jpeg');

$body = NewMessageBody::text('Вот фото!')
    ->withAttachment(NewAttachment::image($token));

$bot->sendMessageToChat($chatId, $body);
```

Для типичного upload-and-send сценария используйте helpers:

```php
$bot->sendImageToChat($chatId, './photo.jpg', 'photo.jpg', 'image/jpeg', 'Вот фото!');
$bot->sendVideoToUser($userId, './clip.mp4', 'clip.mp4', 'video/mp4');
$bot->sendFileBytesToChat($chatId, $bytes, 'report.pdf', 'application/pdf', 'Отчёт');
```

Image upload может вернуть MAX `photos` token map вместо одного `token`. Helpers `sendImage*` автоматически сохраняют этот payload и коротко ретраят отправку, пока MAX сообщает, что вложение ещё не обработано.

> Важно: тип `photo` удалён из API MAX. Всегда используйте `UploadType::IMAGE`.

---

## Webhook (без фреймворка)

```php
// webhook.php -- этот файл доступен по вашему HTTPS URL

use Maxoxide\Bot;
use Maxoxide\Dispatcher;
use Maxoxide\WebhookReceiver;

$bot = Bot::fromEnv();
$dp  = new Dispatcher($bot);

$dp->onCommand('/start', function ($ctx) {
    $ctx->bot->sendTextToChat($ctx->update->message->chatId(), 'Hello!');
});

// Передайте тот же secret, что и в SubscribeBody
WebhookReceiver::handle($dp, getenv('WEBHOOK_SECRET') ?: null);
```

Зарегистрировать вебхук один раз:

```php
use Maxoxide\SubscribeBody;

$body = new SubscribeBody('https://your-domain.com/webhook.php');
$body->secret = 'my_secret_123';
$bot->subscribe($body);
```

> MAX требует HTTPS на порту 443. Самоподписанные сертификаты не поддерживаются.

---

## Обработка ошибок

Все ошибки бросают `Maxoxide\MaxException`:

```php
use Maxoxide\MaxException;

try {
    $bot->sendTextToChat($chatId, 'Hello!');
} catch (MaxException $e) {
    echo $e->getApiCode();    // HTTP-статус (0 = сетевая/JSON ошибка)
    echo $e->getMessage();    // описание
}
```

Глобальный обработчик ошибок хендлеров в диспетчере:

```php
use Throwable;

$dp->onError(function (Throwable $e) {
    error_log('[maxoxide] ' . $e->getMessage());
});
```

---

## Запуск тестов

```bash
composer install
./vendor/bin/phpunit tests
```

---

## Известные ограничения MAX (апрель 2026)

- `Button::requestContact` -- кнопка отправляется, но входящие contact-вложения наблюдались с пустыми `contact_id` и `vcf_phone`.
- `Button::requestGeoLocation` может прийти как структурированное `location`-вложение или как клиентский fallback в виде map card/link.
- `sendSenderAction($chatId, SenderAction::TYPING_ON)` получает успешный ответ API, но видимый индикатор набора в клиенте не подтверждён стабильно.
- `setMyCommands` сейчас возвращает `404` на `POST /me/commands`.

---

## Live API тест

Для end-to-end проверки на реальных данных есть интерактивный harness:

```bash
php examples/live_api_test.php
```

В начале он спрашивает язык, токен бота и опциональные настройки:

- URL бота для тестера
- webhook URL и secret для проверки subscribe/unsubscribe
- путь к локальному файлу для `uploadFile`
- опциональные пути к image, video и audio файлам для проверки media helpers
- задержку между запросами, HTTP timeout и polling timeout

Затем harness проходит по фазам:

**Личный чат**: отправка `/live` боту активирует фазу. Проверяются `sendTextToChat`, `sendTextToUser`, `sendMarkdown*`, `sendMessageToChatWithOptions`, inline-клавиатуры с callback/message/contact/location/link кнопками, optional `open_app`, `clipboard`, `answerCallback`, `editMessage`, `getMessage`, `getMessages`, `getMessagesByIds`, `deleteMessage`.

**Загрузки**: `getUploadUrl` для всех типов, `uploadFile`, `uploadBytes`, file helpers для chat/user, byte helpers, optional `sendImageToChat`, `sendVideoToChat`, `getVideo`, `sendAudioToChat` и отправка загруженных вложений обратно в чат.

**Webhook**: `getSubscriptions`, `subscribe`, `unsubscribe`, если указан webhook URL.

**Команды**: экспериментальная проверка `setMyCommands`. MAX сейчас возвращает `404`.

**Групповой чат**: отправка `/group_live` в группе активирует фазу. Проверяются `getChat`, `getMembers`, `getMembersByIds`, `getAdmins`, `getMyMembership`, typed sender actions, sender-action helpers, pin/unpin, `editChat` с авто-откатом, optional `addAdmins`/`removeAdmin`, `addMembers`, `removeMember`, `deleteChat`, `leaveChat`.

Для каждого шага фиксируется `PASS`, `FAIL` или `SKIP`, а в конце печатается полная сводка.

---

## Лицензия

[MIT](https://choosealicense.com/licenses/mit/)
