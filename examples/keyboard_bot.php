<?php

/**
 * Keyboard bot — demonstrates inline keyboard buttons and callback handling.
 *
 * Run:
 *   MAX_BOT_TOKEN=your_token php examples/keyboard_bot.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Maxoxide\Bot;
use Maxoxide\Context;
use Maxoxide\Dispatcher;
use Maxoxide\AnswerCallbackBody;
use Maxoxide\Button;
use Maxoxide\KeyboardPayload;
use Maxoxide\NewMessageBody;

$bot = Bot::fromEnv();
$dp  = new Dispatcher($bot);

// /menu command — shows an inline keyboard
$dp->onCommand('/menu', function (Context $ctx) {
    if ($ctx->update->message === null) {
        return;
    }

    $keyboard = new KeyboardPayload([
        [
            Button::callback('🔴 Красный', 'color:red'),
            Button::callback('🟢 Зелёный', 'color:green'),
            Button::callback('🔵 Синий',   'color:blue'),
        ],
        [
            Button::link('📖 Документация', 'https://dev.max.ru/docs-api'),
        ],
    ]);

    $body = NewMessageBody::text('Выбери цвет:')->withKeyboard($keyboard);
    $ctx->bot->sendMessageToChat($ctx->update->message->chatId(), $body);
});

// Specific callback payload handlers
$dp->onCallbackPayload('color:red', function (Context $ctx) {
    if ($ctx->update->callback !== null) {
        $answer = new AnswerCallbackBody($ctx->update->callback->callbackId);
        $answer->notification = 'Ты выбрал красный! 🔴';
        $ctx->bot->answerCallback($answer);
    }
});

$dp->onCallbackPayload('color:green', function (Context $ctx) {
    if ($ctx->update->callback !== null) {
        $answer = new AnswerCallbackBody($ctx->update->callback->callbackId);
        $answer->notification = 'Ты выбрал зелёный! 🟢';
        $ctx->bot->answerCallback($answer);
    }
});

$dp->onCallbackPayload('color:blue', function (Context $ctx) {
    if ($ctx->update->callback !== null) {
        $answer = new AnswerCallbackBody($ctx->update->callback->callbackId);
        $answer->notification = 'Ты выбрал синий! 🔵';
        $ctx->bot->answerCallback($answer);
    }
});

// Catch-all for any other callback
$dp->onCallback(function (Context $ctx) {
    if ($ctx->update->callback !== null) {
        $answer = new AnswerCallbackBody($ctx->update->callback->callbackId);
        $answer->notification = 'Неизвестная кнопка';
        $ctx->bot->answerCallback($answer);
    }
});

$dp->startPolling();
