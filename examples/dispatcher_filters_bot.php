<?php

/**
 * Demonstrates composable Dispatcher filters, startup hooks, raw update hooks,
 * and scheduled tasks.
 *
 * Run:
 *   MAX_BOT_TOKEN=your_token php examples/dispatcher_filters_bot.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Maxoxide\AttachmentKind;
use Maxoxide\Bot;
use Maxoxide\Context;
use Maxoxide\Dispatcher;
use Maxoxide\Filter;
use Maxoxide\RawUpdateContext;
use Maxoxide\ScheduledTaskContext;
use Maxoxide\StartContext;

$bot = Bot::fromEnv();
$dp = new Dispatcher($bot);

$dp->onStart(static function (StartContext $ctx): void {
    $me = $ctx->bot->getMe();
    error_log('[maxoxide] polling started for ' . $me->displayName());
});

$dp->onRawUpdate(static function (RawUpdateContext $ctx): void {
    $updateType = isset($ctx->raw['update_type']) ? (string) $ctx->raw['update_type'] : 'unknown';
    error_log("[maxoxide] raw update: {$updateType}");
});

$dp->task(300, static function (ScheduledTaskContext $ctx): void {
    $me = $ctx->bot->getMe();
    error_log("[maxoxide] scheduled health check for user_id={$me->userId}");
});

$dp->onUpdate(
    Filter::message()->andFilter(Filter::textContains('ping')),
    static function (Context $ctx): void {
        if ($ctx->update->message !== null) {
            $ctx->bot->sendTextToChat($ctx->update->message->chatId(), 'pong');
        }
    }
);

$dp->onUpdate(
    Filter::message()->andFilter(Filter::hasMedia()),
    static function (Context $ctx): void {
        if ($ctx->update->message !== null) {
            $ctx->bot->sendTextToChat($ctx->update->message->chatId(), 'media attachment received');
        }
    }
);

$dp->onUpdate(
    Filter::message()->andFilter(Filter::hasAttachmentType(AttachmentKind::FILE)),
    static function (Context $ctx): void {
        if ($ctx->update->message !== null) {
            $ctx->bot->sendTextToChat($ctx->update->message->chatId(), 'file attachment received');
        }
    }
);

$dp->onUpdate(Filter::unknownUpdate(), static function (Context $ctx): void {
    $type = $ctx->update->updateType() ?? 'unknown';
    error_log("[maxoxide] unsupported update type: {$type}");
});

$dp->startPolling();
