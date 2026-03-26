<?php

/**
 * Echo bot — mirrors every received message back to the sender.
 *
 * Run:
 *   MAX_BOT_TOKEN=your_token php examples/echo_bot.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Maxoxide\Bot;
use Maxoxide\Context;
use Maxoxide\Dispatcher;

$bot = Bot::fromEnv();
$dp  = new Dispatcher($bot);

// /start command
$dp->onCommand('/start', function (Context $ctx) {
    if ($ctx->update->message !== null) {
        $ctx->bot->sendMarkdownToChat(
            $ctx->update->message->chatId(),
            'Привет! Я эхо-бот. Напиши что-нибудь, и я отвечу тем же 🤖'
        );
    }
});

// Mirror every other message
$dp->onMessage(function (Context $ctx) {
    if ($ctx->update->message !== null) {
        $text = $ctx->update->message->text() ?? '(без текста)';
        $ctx->bot->sendTextToChat($ctx->update->message->chatId(), $text);
    }
});

$dp->startPolling();
