[![Packagist](https://img.shields.io/packagist/v/mammothcoding/maxoxide.svg)](https://packagist.org/packages/mammothcoding/maxoxide)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![MIT](https://img.shields.io/badge/license-MIT-blue.svg)](https://choosealicense.com/licenses/mit/)
[![Build Status](https://github.com/mammothcoding/maxoxide_php/actions/workflows/php.yml/badge.svg?event=push)](https://github.com/mammothcoding/maxoxide_php/actions/workflows/php.yml)

Readme in different languages:
[EN](README.md) · [RU](README.ru.md)

# maxoxide-php

A synchronous PHP library for building bots on the [Max messenger](https://max.ru) platform.
Inspired by the Rust library [maxoxide](https://github.com/mammothcoding/maxoxide).

Requires PHP 7.4+, the `curl` and `json` extensions. No third-party runtime dependencies.
This PHP version is aligned with the Rust `maxoxide` 2.1.0 API surface: filtered polling, message markup parsing, new dialog updates, contact hash/max_info helpers, chat buttons, typed sender actions, media helpers, `open_app`/`clipboard` buttons, and image `photos` upload payloads.

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
│   ├── Update.php             -- Update, Callback, UpdatesResponse, RawUpdatesResponse
│   ├── Bot.php                -- cURL HTTP client, API methods, file uploads
│   ├── Dispatcher.php         -- Dispatcher, Context, filters, long polling
│   └── Webhook.php            -- WebhookReceiver with no framework dependency
├── examples/
│   ├── echo_bot.php           -- echo bot via long polling
│   ├── keyboard_bot.php       -- inline keyboard and callback buttons
│   ├── dispatcher_filters_bot.php -- composable filters, raw hooks, tasks
│   ├── media_bot.php          -- upload-and-send helpers for media/files
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
| `editMyInfo(body)` | Edit bot profile, commands, or avatar via `PATCH /me` |
| `sendTextToChat(chatId, text)` | Send plain text to a dialog/group/channel by `chatId` |
| `sendTextToUser(userId, text)` | Send plain text to a user by global MAX `userId` |
| `sendMarkdownToChat(chatId, text)` | Send Markdown to a dialog/group/channel |
| `sendMarkdownToUser(userId, text)` | Send Markdown to a user by `userId` |
| `sendMessageToChat(chatId, body)` | Send a message with attachments or a keyboard by `chatId` |
| `sendMessageToChatWithOptions(chatId, body, options)` | Send with query options such as `disable_link_preview` |
| `sendMessageToUser(userId, body)` | Send a message with attachments or a keyboard by `userId` |
| `sendMessageToUserWithOptions(userId, body, options)` | Send to a user with query options |
| `editMessage(mid, body)` | Edit a message |
| `deleteMessage(mid)` | Delete a message |
| `getMessage(mid)` | Get a message by ID |
| `getMessages(chatId, ...)` | Get messages from a chat |
| `getMessagesByIds(ids, ...)` | Get one or more messages by message IDs |
| `getVideo(videoToken)` | Get uploaded video metadata and playback URLs |
| `answerCallback(body)` | Answer an inline button press |
| `getChats(...)` | List group chats |
| `getChat(chatId)` | Chat info |
| `editChat(chatId, body)` | Edit title or description |
| `deleteChat(chatId)` | Delete a chat |
| `sendAction(chatId, action)` | Typing indicator and other chat actions |
| `sendSenderAction(chatId, action)` | Send a typed sender action value |
| `sendTypingOn(chatId)` / `markSeen(chatId)` | Convenience sender actions |
| `sendSendingImage/Video/Audio/File(chatId)` | Convenience upload indicators |
| `getPinnedMessage(chatId)` | Get the pinned message |
| `pinMessage(chatId, body)` | Pin a message |
| `unpinMessage(chatId)` | Unpin |
| `getMembers(chatId, ...)` | List chat members |
| `getMembersByIds(chatId, userIds)` | Get selected chat members |
| `addMembers(chatId, userIds)` | Add members |
| `removeMember(chatId, userId)` | Remove a member |
| `removeMemberWithOptions(chatId, userId, options)` | Remove a member with options such as `block=true` |
| `getAdmins(chatId)` | List admins |
| `addAdmins(chatId, admins)` | Grant administrator rights |
| `removeAdmin(chatId, userId)` | Revoke administrator rights |
| `getMyMembership(chatId)` | Get the bot's own membership |
| `leaveChat(chatId)` | Leave a chat |
| `getSubscriptions()` | List webhook subscriptions |
| `subscribe(body)` | Register a webhook |
| `unsubscribe(url)` | Remove a webhook |
| `getUpdates(...)` | Run a single long-poll request |
| `getUpdatesRaw(...)` | Run a raw long-poll request before typed parsing |
| `getUpdatesWithTypes(..., types)` | Long polling limited to selected update types |
| `getUpdatesRawWithTypes(..., types)` | Raw JSON long polling limited to selected update types |
| `getUploadUrl(type)` | Get the MAX upload URL for an attachment type |
| `uploadFile(type, path, name, mime)` | Full two-step file upload |
| `uploadBytes(type, bytes, name, mime)` | Same, from raw bytes |
| `sendImage/Video/Audio/FileToChat(...)` | Upload a local file and send it to a chat |
| `sendImage/Video/Audio/FileToUser(...)` | Upload a local file and send it to a user |
| `sendImage/Video/Audio/FileBytesToChat(...)` | Upload bytes and send to a chat |
| `sendImage/Video/Audio/FileBytesToUser(...)` | Upload bytes and send to a user |
| `setMyCommands(commands)` | Experimental: MAX currently returns `404` |

---

## userId vs chatId

These two identifiers are different:

- `userId` is the global ID of a MAX user.
- `chatId` is the ID of a concrete dialog, group, or channel.
- In a private chat, `message->sender->userId` identifies the user, while `message->chatId()` identifies the specific dialog with the bot.
- Use `sendTextToChat` / `sendMessageToChat` when you know the dialog or group `chatId`.
- Use `sendTextToUser` / `sendMessageToUser` when you only know the global `userId`.

`User` and `ChatMember` now expose MAX-style profile fields: `firstName`, `lastName`, `username`, `description`, `avatarUrl`, `fullAvatarUrl`, and `commands` where applicable. Use `displayName()` when you need one printable name. The legacy `name` alias remains available for existing PHP callers.

---

## Dispatcher filters

```php
use Maxoxide\AttachmentKind;
use Maxoxide\Filter;

$dp->onCommand('/start', $handler);          // specific command
$dp->onMessage($handler);                    // any new message
$dp->onEditedMessage($handler);              // message edit
$dp->onCallback($handler);                   // any callback
$dp->onCallbackPayload('btn:ok', $handler);  // exact payload
$dp->onBotStarted($handler);                 // first bot start
$dp->onBotAdded($handler);                   // bot added to a chat
$dp->onBotStopped($handler);                 // user stopped the bot
$dp->onDialogMuted($handler);                // private dialog muted
$dp->onMessageChatCreated($handler);         // chat button created a chat
$dp->onFilter(fn($u) => ..., $handler);      // custom predicate
$dp->on($handler);                           // every update

$dp->onUpdate(
    Filter::message()
        ->andFilter(Filter::chat($chatId))
        ->andFilter(Filter::textContains('ping')),
    $handler
);

$dp->onUpdate(Filter::hasAttachmentType(AttachmentKind::FILE), $handler);
$dp->onUpdate(Filter::hasMedia(), $handler);
$dp->onUpdate(Filter::unknownUpdate(), $handler);

$dp->onRawUpdate($handler);                  // raw JSON for every update
$dp->onStart($handler);                      // once before polling starts
$dp->task(300, $handler);                    // periodic task while polling
```

The first matching handler wins. Register more specific filters earlier.
Raw handlers always run before typed handlers. Unknown future update types are parsed as `Update` objects with `raw()` preserved.

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
    [
        Button::link('Website', 'https://max.ru'),
        Button::clipboard('Copy code', 'promo-123'),
    ],
    [
        Button::requestContact('Share contact'),
        Button::requestGeoLocation('Share location'),
    ],
]);

$body = NewMessageBody::text('Are you sure?')->withKeyboard($keyboard);
$bot->sendMessageToChat($chatId, $body);
```

`Button::openAppFull($text, $webApp, $payload, $contactId)` serializes the official MAX `open_app` wire model with `web_app`, optional `payload`, and optional `contact_id`.
`Button::chatFull($text, $chatTitle, $chatDescription, $startPayload, $uuid)` serializes the documented `chat` button model. Current live testing still treats send-time `chat` button rejection as a MAX platform limitation when the API returns `400 Can't deserialize body`.

---

## File uploads

MAX uses a two-step upload flow. `uploadFile` and `uploadBytes` return a usable attachment token:

```php
use Maxoxide\NewAttachment;
use Maxoxide\NewMessageBody;
use Maxoxide\UploadType;

$token = $bot->uploadFile(UploadType::IMAGE, './photo.jpg', 'photo.jpg', 'image/jpeg');

$body = NewMessageBody::text('Here is a photo!')
    ->withAttachment(NewAttachment::image($token));

$bot->sendMessageToChat($chatId, $body);
```

For the common upload-and-send flow, use the helpers:

```php
$bot->sendImageToChat($chatId, './photo.jpg', 'photo.jpg', 'image/jpeg', 'Here is a photo!');
$bot->sendVideoToUser($userId, './clip.mp4', 'clip.mp4', 'video/mp4');
$bot->sendFileBytesToChat($chatId, $bytes, 'report.pdf', 'application/pdf', 'Report');
```

Image uploads can return a MAX `photos` token map instead of a single `token`. The `sendImage*` helpers preserve that payload automatically and retry briefly while MAX reports the attachment as not processed yet.

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
WebhookReceiver::handle($dp, getenv('WEBHOOK_SECRET') ?: null);
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
use Throwable;

$dp->onError(function (Throwable $e) {
    error_log('[maxoxide] ' . $e->getMessage());
});
```

---

## Running tests

```bash
composer install
./vendor/bin/phpunit tests
```

---

## Known MAX platform gaps (May 2026)

- `Button::requestContact` is live-confirmed to deliver `vcf_info`, a valid `hash`, and `max_info`; `vcf_phone` may still be empty, so use `phonesFromVcf()` as a fallback.
- `Button::requestGeoLocation` is live-confirmed to deliver a structured `location` attachment with coordinates.
- `Button::chatFull()` follows the documented `chat` button JSON, but current live `POST /messages` requests may be rejected with `400 Can't deserialize body`.
- `setMyCommands` currently returns `404` from `POST /me/commands`.

---

## Live API test

There is an interactive harness for end-to-end checks against the real API:

```bash
php examples/live_api_test.php
```

At startup it asks for the language, update transport, bot token, and optional settings:

- update transport: `long_polling` or `webhook`
- bot URL for the tester
- webhook URL and secret for subscribe/unsubscribe checks, webhook mode, and restoring temporarily disabled subscriptions
- local webhook listen address when `webhook` transport is selected
- path to a local file for `uploadFile`
- optional paths to image, video, and audio files for media helper checks
- request delay, HTTP timeout, and polling timeout

Then the harness walks through each phase:

**Private chat**: sending `/live` to the bot activates the phase. It checks `sendTextToChat`, `sendTextToUser`, `sendMarkdown*`, message markup returned by `getMessage`, `sendMessageToChatWithOptions`, inline keyboards with callback/message/contact/location/link buttons, optional `open_app`, `clipboard`, opt-in `chat` button / `message_chat_created`, `answerCallback`, `editMessage`, `getMessage`, `getMessages`, `getMessagesByIds`, and `deleteMessage`.

**Uploads**: `getUploadUrl` for all types, `uploadFile`, `uploadBytes`, file helpers for chat/user, byte helpers, optional `sendImageToChat`, `sendVideoToChat`, `getVideo`, `sendAudioToChat`, and sending uploaded attachments back to the chat.

**Updates transport**: in `long_polling` mode the harness checks active webhook subscriptions, can temporarily unsubscribe them, restores them at the end, and probes `getUpdatesWithTypes` / `getUpdatesRawWithTypes`. In `webhook` mode it starts a local receiver and manual waits consume incoming webhook POSTs.

**Webhook**: `getSubscriptions`, `subscribe`, `unsubscribe` if a webhook URL is provided and the run is not already using webhook transport.

**Commands**: experimental `setMyCommands` check. MAX currently returns `404`.

**Group chat**: sending `/group_live` in a group activates the phase. It checks `getChat`, `getMembers`, `getMembersByIds`, `getAdmins`, `getMyMembership`, typed sender actions, sender-action helpers, pin/unpin, `editChat` with automatic rollback, optional `addAdmins`/`removeAdmin`, `addMembers`, `removeMember`, opt-in `removeMemberWithOptions(..., block=true)`, `deleteChat`, and `leaveChat`.

**Optional dialog events**: at the end the harness can wait for `bot_stopped`, `dialog_cleared`, `dialog_muted`, `dialog_unmuted`, and `dialog_removed`.

Each step is reported as `PASS`, `FAIL`, or `SKIP`, and the full summary is printed at the end.

---

## License

[MIT](https://choosealicense.com/licenses/mit/)
