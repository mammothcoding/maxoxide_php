[![Packagist](https://img.shields.io/packagist/v/mammothcoding/maxoxide.svg)](https://packagist.org/packages/mammothcoding/maxoxide)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![MIT](https://img.shields.io/badge/license-MIT-blue.svg)](https://choosealicense.com/licenses/mit/)

Readme in different languages:
[EN](README.md) · [RU](README.ru.md)

# maxoxide-php

A synchronous PHP library for building bots on the [Max messenger](https://max.ru) platform.
Inspired by the Rust library [maxoxide](https://github.com/mammothcoding/maxoxide).

Requires PHP 7.4+, the `curl` and `json` extensions. No third-party runtime dependencies.

---

## Installation

```bash
composer require mammothcoding/maxoxide
```

---

## Quick start

```php
<?php
require 'vendor/autoload.php';

use Maxoxide\Bot;
use Maxoxide\Context;
use Maxoxide\Dispatcher;

$bot = Bot::fromEnv();   // reads MAX_BOT_TOKEN from the environment
$dp  = new Dispatcher($bot);

$dp->onCommand('/start', function (Context $ctx) {
    if ($ctx->update->message !== null) {
        $ctx->bot->sendMarkdownToChat(
            $ctx->update->message->chatId(),
            'Hello!'
        );
    }
});

$dp->onMessage(function (Context $ctx) {
    if ($ctx->update->message !== null) {
        $text = $ctx->update->message->text() ?? '(no text)';
        $ctx->bot->sendTextToChat($ctx->update->message->chatId(), $text);
    }
});

$dp->startPolling();
```

```bash
MAX_BOT_TOKEN=your_token php examples/echo_bot.php
```

---

## Project structure

```text
maxoxide-php/
├── composer.json               -- dependencies and PSR-4 autoloading
├── README.md
├── README.ru.md
├── bootstrap.php              -- manual bootstrap for running examples from source tree
├── src/
│   ├── MaxException.php       -- the single exception type
│   ├── Types.php              -- data types: User, Chat, Message, Button, Keyboard, ...
│   ├── Update.php             -- Update, Callback, UpdatesResponse
│   ├── Bot.php                -- cURL HTTP client, API methods, file uploads
│   ├── Dispatcher.php         -- Dispatcher, Context, filters, long polling
│   └── Webhook.php            -- WebhookReceiver with no framework dependency
├── examples/
│   ├── echo_bot.php           -- echo bot via long polling
│   ├── keyboard_bot.php       -- inline keyboard and callback buttons
│   ├── webhook_bot.php        -- webhook receiver example
│   └── live_api_test.php      -- interactive harness against the real MAX API
└── tests/
    ├── TypesTest.php          -- unit tests for types, filters, serialization
    └── BotSendMessageTest.php -- regression tests for POST /messages dispatch
```

---

## API methods

| Method | Description |
|--------|-------------|
| `getMe()` | Bot info |
| `sendTextToChat(chatId, text)` | Send plain text to a dialog/group/channel by `chatId` |
| `sendTextToUser(userId, text)` | Send plain text to a user by global MAX `userId` |
| `sendMarkdownToChat(chatId, text)` | Send Markdown to a dialog/group/channel |
| `sendMarkdownToUser(userId, text)` | Send Markdown to a user by `userId` |
| `sendMessageToChat(chatId, body)` | Send a message with attachments or a keyboard by `chatId` |
| `sendMessageToUser(userId, body)` | Send a message with attachments or a keyboard by `userId` |
| `editMessage(mid, body)` | Edit a message |
| `deleteMessage(mid)` | Delete a message |
| `getMessage(mid)` | Get a message by ID |
| `getMessages(chatId, ...)` | Get messages from a chat |
| `answerCallback(body)` | Answer an inline button press |
| `getChats(...)` | List group chats |
| `getChat(chatId)` | Chat info |
| `editChat(chatId, body)` | Edit title or description |
| `deleteChat(chatId)` | Delete a chat |
| `sendAction(chatId, action)` | Typing indicator and other chat actions |
| `getPinnedMessage(chatId)` | Get the pinned message |
| `pinMessage(chatId, body)` | Pin a message |
| `unpinMessage(chatId)` | Unpin |
| `getMembers(chatId, ...)` | List chat members |
| `addMembers(chatId, userIds)` | Add members |
| `removeMember(chatId, userId)` | Remove a member |
| `getAdmins(chatId)` | List admins |
| `getMyMembership(chatId)` | Get the bot's own membership |
| `leaveChat(chatId)` | Leave a chat |
| `getSubscriptions()` | List webhook subscriptions |
| `subscribe(body)` | Register a webhook |
| `unsubscribe(url)` | Remove a webhook |
| `getUpdates(...)` | Run a single long-poll request |
| `uploadFile(type, path, name, mime)` | Full two-step file upload |
| `uploadBytes(type, bytes, name, mime)` | Same, from raw bytes |
| `setMyCommands(commands)` | Experimental: MAX currently returns `404` |

---

## userId vs chatId

These two identifiers are different:

- `userId` is the global ID of a MAX user.
- `chatId` is the ID of a concrete dialog, group, or channel.
- In a private chat, `message->sender->userId` identifies the user, while `message->chatId()` identifies the specific dialog with the bot.
- Use `sendTextToChat` / `sendMessageToChat` when you know the dialog or group `chatId`.
- Use `sendTextToUser` / `sendMessageToUser` when you only know the global `userId`.

---

## Dispatcher filters

```php
$dp->onCommand('/start', $handler);          // specific command
$dp->onMessage($handler);                    // any new message
$dp->onEditedMessage($handler);              // message edit
$dp->onCallback($handler);                   // any callback
$dp->onCallbackPayload('btn:ok', $handler);  // exact payload
$dp->onBotStarted($handler);                 // first bot start
$dp->onBotAdded($handler);                   // bot added to a chat
$dp->onFilter(fn($u) => ..., $handler);      // custom predicate
$dp->on($handler);                           // every update
```

The first matching handler wins. Register more specific filters earlier.

---

## Inline keyboard

```php
use Maxoxide\Button;
use Maxoxide\KeyboardPayload;
use Maxoxide\NewMessageBody;

$keyboard = new KeyboardPayload([
    [
        Button::callback('Yes', 'answer:yes'),
        Button::callback('No', 'answer:no'),
    ],
    [Button::link('Website', 'https://max.ru')],
]);

$body = NewMessageBody::text('Are you sure?')->withKeyboard($keyboard);
$bot->sendMessageToChat($chatId, $body);
```

---

## File uploads

MAX uses a two-step upload flow. `uploadFile` and `uploadBytes` handle it automatically:

```php
use Maxoxide\NewAttachment;
use Maxoxide\NewMessageBody;
use Maxoxide\UploadType;

$token = $bot->uploadFile(UploadType::IMAGE, './photo.jpg', 'photo.jpg', 'image/jpeg');

$body = NewMessageBody::text('Here is a photo!')
    ->withAttachment(NewAttachment::image($token));

$bot->sendMessageToChat($chatId, $body);
```

> Important: the `photo` type has been removed from the MAX API. Always use `UploadType::IMAGE`.

---

## Webhook (without a framework)

```php
// webhook.php -- this file is exposed via your HTTPS URL

use Maxoxide\Bot;
use Maxoxide\Dispatcher;
use Maxoxide\WebhookReceiver;

$bot = Bot::fromEnv();
$dp  = new Dispatcher($bot);

$dp->onCommand('/start', function ($ctx) {
    $ctx->bot->sendTextToChat($ctx->update->message->chatId(), 'Hello!');
});

// Pass the same secret that you used in SubscribeBody
WebhookReceiver::handle($dp, secret: getenv('WEBHOOK_SECRET') ?: null);
```

Register the webhook once:

```php
use Maxoxide\SubscribeBody;

$body = new SubscribeBody('https://your-domain.com/webhook.php');
$body->secret = 'my_secret_123';
$bot->subscribe($body);
```

> MAX requires HTTPS on port 443. Self-signed certificates are not supported.

---

## Error handling

All API errors throw `Maxoxide\MaxException`:

```php
use Maxoxide\MaxException;

try {
    $bot->sendTextToChat($chatId, 'Hello!');
} catch (MaxException $e) {
    echo $e->getApiCode();    // HTTP status (0 = network/JSON error)
    echo $e->getMessage();    // error description
}
```

Global handler for dispatcher callback errors:

```php
$dp->onError(function (\Throwable $e) {
    error_log('[maxoxide] ' . $e->getMessage());
});
```

---

## Running tests

```bash
composer install
./vendor/bin/phpunit tests/
```

---

## Known MAX platform gaps (March 2026)

- `Button::requestContact` sends successfully, but incoming contact attachments were observed with empty `contact_id` and `vcf_phone`.
- `Button::requestGeoLocation` shows a location card in the client, but the bot did not receive a matching update in live polling tests.
- `sendAction("typing_on")` returns success from the API, but the typing indicator is not reliably confirmed in the client.
- `setMyCommands` currently returns `404` from `POST /me/commands`.

---

## Live API test

There is an interactive harness for end-to-end checks against the real API:

```bash
php examples/live_api_test.php
```

At startup it asks for the language, bot token, and optional settings:

- bot URL for the tester
- webhook URL and secret for subscribe/unsubscribe checks
- path to a local file for `upload_file`
- request delay, HTTP timeout, and polling timeout

Then the harness walks through each phase:

**Private chat**: sending `/live` to the bot activates the phase. It checks `send_text_to_chat`, `send_text_to_user`, `send_markdown_*`, an inline keyboard with all supported button types (callback, message, contact, location, link), `answer_callback`, `edit_message`, `get_message`, `get_messages`, and `delete_message`.

**Uploads**: `get_upload_url` for all types, `upload_file`, `upload_bytes`, and sending uploaded attachments back to the chat.

**Webhook**: `get_subscriptions`, `subscribe`, `unsubscribe` if a webhook URL is provided.

**Commands**: experimental `set_my_commands` check. MAX currently returns `404`.

**Group chat**: sending `/group_live` in a group activates the phase. It checks `get_chat`, `get_members`, `get_admins`, `get_my_membership`, `send_action` (`typing_on`), pin/unpin, `edit_chat` with automatic rollback, `add_members`, `remove_member`, `delete_chat`, and `leave_chat`.

Each step is reported as `PASS`, `FAIL`, or `SKIP`, and the full summary is printed at the end.

---

## License

[MIT](https://choosealicense.com/licenses/mit/)
