<?php

/**
 * Webhook bot — production alternative to long polling.
 *
 * Deploy this file to your HTTPS server and point your web server at it.
 * Max requires HTTPS on port 443; no self-signed certificates.
 *
 * Register the webhook once (run this from CLI or a separate script):
 *
 *   $bot = new \Maxoxide\Bot('your_token');
 *   $body = new \Maxoxide\SubscribeBody('https://your-domain.com/webhook.php');
 *   $body->secret = 'my_secret_123';
 *   $bot->subscribe($body);
 *
 * Then set WEBHOOK_SECRET in your env / web-server config and deploy this file.
 *
 * ENV vars used:
 *   MAX_BOT_TOKEN   — required
 *   WEBHOOK_SECRET  — optional but strongly recommended
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Maxoxide\Bot;
use Maxoxide\Context;
use Maxoxide\Dispatcher;
use Maxoxide\WebhookReceiver;

$bot = Bot::fromEnv();
$dp  = new Dispatcher($bot);

$dp->onCommand('/start', function (Context $ctx) {
    if ($ctx->update->message !== null) {
        $ctx->bot->sendMarkdownToChat(
            $ctx->update->message->chatId(),
            'Привет! Бот запущен через Webhook 🚀'
        );
    }
});

$dp->onMessage(function (Context $ctx) {
    if ($ctx->update->message !== null) {
        $text = $ctx->update->message->text() ?? '(без текста)';
        $ctx->bot->sendTextToChat($ctx->update->message->chatId(), $text);
    }
});

$secret = getenv('WEBHOOK_SECRET') ?: null;
WebhookReceiver::handle($dp, $secret);
