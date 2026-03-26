[![Packagist](https://img.shields.io/packagist/v/mammothcoding/maxoxide.svg)](https://packagist.org/packages/mammothcoding/maxoxide)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![MIT](https://img.shields.io/badge/license-MIT-blue.svg)](https://choosealicense.com/licenses/mit/)

# maxoxide-php

Синхронная PHP-библиотека для создания ботов на платформе [Max мессенджер](https://max.ru).
Вдохновлена Rust-библиотекой [maxoxide](https://github.com/mammothcoding/maxoxide).

Требует PHP 7.4+, расширения `curl` и `json`. Никаких внешних зависимостей в runtime.

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
            'Привет! 👋'
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
MAX_BOT_TOKEN=your_token php echo_bot.php
```

---

## Структура проекта

```
maxoxide-php/
├── composer.json               — зависимости и автозагрузка (PSR-4)
├── README.md
├── src/
│   ├── MaxException.php        — единственный класс ошибок
│   ├── Types.php               — все типы данных: User, Chat, Message, Button, Keyboard, …
│   ├── Update.php              — Update, Callback, UpdatesResponse
│   ├── Bot.php                 — HTTP-клиент на cURL, все методы API, загрузка файлов
│   ├── Dispatcher.php          — Dispatcher, Context, фильтры, long polling
│   └── Webhook.php             — WebhookReceiver (без зависимостей)
├── examples/
│   ├── echo_bot.php            — эхо-бот (long polling)
│   ├── keyboard_bot.php        — inline-клавиатура и callback-кнопки
│   ├── webhook_bot.php         — вебхук-приёмник
│   └── live_api_test.php       — интерактивный harness-тест по реальному API
└── tests/
    └── TypesTest.php           — юнит-тесты типов, фильтров, сериализации
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
| `sendMessageToChat(chatId, body)` | Сообщение с вложениями / кнопками по `chatId` |
| `sendMessageToUser(userId, body)` | Сообщение с вложениями / кнопками по `userId` |
| `editMessage(mid, body)` | Редактировать сообщение |
| `deleteMessage(mid)` | Удалить сообщение |
| `getMessage(mid)` | Получить сообщение по ID |
| `getMessages(chatId, …)` | Список сообщений чата |
| `answerCallback(body)` | Ответ на нажатие кнопки |
| `getChats(…)` | Список групповых чатов |
| `getChat(chatId)` | Информация о чате |
| `editChat(chatId, body)` | Изменить название / описание |
| `deleteChat(chatId)` | Удалить чат |
| `sendAction(chatId, action)` | Индикатор набора текста и др. |
| `getPinnedMessage(chatId)` | Закреплённое сообщение |
| `pinMessage(chatId, body)` | Закрепить сообщение |
| `unpinMessage(chatId)` | Открепить |
| `getMembers(chatId, …)` | Участники чата |
| `addMembers(chatId, userIds)` | Добавить участников |
| `removeMember(chatId, userId)` | Удалить участника |
| `getAdmins(chatId)` | Администраторы |
| `getMyMembership(chatId)` | Членство бота в чате |
| `leaveChat(chatId)` | Выйти из чата |
| `getSubscriptions()` | Список подписок на вебхуки |
| `subscribe(body)` | Зарегистрировать вебхук |
| `unsubscribe(url)` | Удалить вебхук |
| `getUpdates(…)` | Разовый long-poll запрос |
| `uploadFile(type, path, name, mime)` | Двухшаговая загрузка файла |
| `uploadBytes(type, bytes, name, mime)` | То же, из байт |
| `setMyCommands(commands)` | Экспериментально — MAX возвращает 404 |

---

## userId vs chatId

Эти два идентификатора разные:

- `userId` — глобальный ID пользователя в MAX.
- `chatId` — ID конкретного диалога, группы или канала.
- В личном чате `message->sender->userId` — это пользователь, а `message->chatId()` — конкретный диалог бота с ним.
- `sendTextToChat / sendMessageToChat` — когда есть `chatId` диалога или группы.
- `sendTextToUser / sendMessageToUser` — когда есть только глобальный `userId`.

---

## Фильтры диспетчера

```php
$dp->onCommand('/start', $handler);          // конкретная команда
$dp->onMessage($handler);                    // любое новое сообщение
$dp->onEditedMessage($handler);              // редактирование
$dp->onCallback($handler);                   // любой callback
$dp->onCallbackPayload('btn:ok', $handler);  // конкретный payload
$dp->onBotStarted($handler);                 // первый запуск бота
$dp->onBotAdded($handler);                   // добавление в чат
$dp->onFilter(fn($u) => ..., $handler);      // произвольный предикат
$dp->on($handler);                           // каждое обновление
```

Срабатывает первый подходящий хендлер. Более специфичные фильтры регистрируйте раньше.

---

## Inline-клавиатура

```php
use Maxoxide\Button;
use Maxoxide\KeyboardPayload;
use Maxoxide\NewMessageBody;

$keyboard = new KeyboardPayload([
    [
        Button::callback('Да ✅', 'answer:yes'),
        Button::callback('Нет ❌', 'answer:no'),
    ],
    [Button::link('🌐 Сайт', 'https://max.ru')],
]);

$body = NewMessageBody::text('Вы уверены?')->withKeyboard($keyboard);
$bot->sendMessageToChat($chatId, $body);
```

---

## Загрузка файлов

MAX использует двухшаговый процесс. `uploadFile` / `uploadBytes` делают его автоматически:

```php
use Maxoxide\NewAttachment;
use Maxoxide\NewMessageBody;
use Maxoxide\UploadType;

$token = $bot->uploadFile(UploadType::IMAGE, './photo.jpg', 'photo.jpg', 'image/jpeg');

$body = NewMessageBody::text('Вот фото!')
    ->withAttachment(NewAttachment::image($token));

$bot->sendMessageToChat($chatId, $body);
```

> **Важно:** тип `photo` удалён из API Max. Всегда используйте `UploadType::IMAGE`.

---

## Webhook (без фреймворка)

```php
// webhook.php — этот файл доступен по вашему HTTPS URL

use Maxoxide\Bot;
use Maxoxide\Dispatcher;
use Maxoxide\WebhookReceiver;

$bot = Bot::fromEnv();
$dp  = new Dispatcher($bot);

$dp->onCommand('/start', function ($ctx) {
    $ctx->bot->sendTextToChat($ctx->update->message->chatId(), 'Hello!');
});

// Передайте тот же secret, что и в SubscribeBody
WebhookReceiver::handle($dp, secret: getenv('WEBHOOK_SECRET') ?: null);
```

Зарегистрировать вебхук один раз:

```php
use Maxoxide\SubscribeBody;

$body = new SubscribeBody('https://your-domain.com/webhook.php');
$body->secret = 'my_secret_123';
$bot->subscribe($body);
```

> MAX требует HTTPS на порту 443. Самоподписанные сертификаты **не поддерживаются**.

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
$dp->onError(function (\Throwable $e) {
    error_log('[maxoxide] ' . $e->getMessage());
});
```

---

## Запуск тестов

```bash
composer install
./vendor/bin/phpunit tests/
```

---

## Известные ограничения MAX (на март 2026)

- `Button::requestContact` — кнопка отправляется, но контакт приходит с пустыми `contact_id` и `vcf_phone`.
- `Button::requestGeoLocation` — клиент показывает карточку, но бот не получает обновление.
- `sendAction("typing_on")` — API отвечает успехом, но индикатор набора в клиенте не подтверждён.
- `setMyCommands` — `POST /me/commands` возвращает `404`.

---

## Live API тест

Для проверки на реальных данных есть интерактивный harness-тест:

```bash
php examples/live_api_test.php
```

В начале он спрашивает язык, токен бота и опциональные настройки:

- URL бота для тестера
- Webhook URL и secret (для тестирования subscribe/unsubscribe)
- Путь к локальному файлу для `upload_file`
- Задержку между запросами, HTTP timeout, polling timeout

Затем harness проходит по всем фазам:

**Личный чат** — отправка `/live` боту активирует фазу. Проверяются: `send_text_to_chat`, `send_text_to_user`, `send_markdown_*`, inline-клавиатура со всеми типами кнопок (callback, message, contact, location, link), `answer_callback`, `edit_message`, `get_message`, `get_messages`, `delete_message`.

**Загрузки** — `get_upload_url` для всех типов, `upload_file` и `upload_bytes`, отправка загруженного файла в чат.

**Webhook** — `get_subscriptions`, `subscribe`, `unsubscribe` (если указан Webhook URL).

**Команды** — экспериментальный `set_my_commands` (MAX сейчас возвращает 404).

**Групповой чат** — `/group_live` в группе активирует фазу. Проверяются: `get_chat`, `get_members`, `get_admins`, `get_my_membership`, `send_action` (typing_on), pin/unpin, `edit_chat` с авто-откатом, `add_members`, `remove_member`, `delete_chat`, `leave_chat`.

Для каждого шага фиксируется `PASS` / `FAIL` / `SKIP`. В конце выводится полная сводка.

---

## Лицензия

[MIT](https://choosealicense.com/licenses/mit/)
