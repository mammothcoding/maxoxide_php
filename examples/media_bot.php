<?php

/**
 * Media bot - demonstrates upload-and-send helpers for image, video, audio,
 * and file attachments.
 *
 * Run:
 *   MAX_BOT_TOKEN=your_token \
 *   MAX_IMAGE_PATH=./photo.jpg \
 *   MAX_VIDEO_PATH=./clip.mp4 \
 *   MAX_AUDIO_PATH=./track.mp3 \
 *   MAX_FILE_PATH=./report.pdf \
 *   php examples/media_bot.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Maxoxide\Bot;
use Maxoxide\Context;
use Maxoxide\Dispatcher;
use Maxoxide\UploadType;

$bot = Bot::fromEnv();
$dp = new Dispatcher($bot);

$dp->onCommand('/image', static function (Context $ctx): void {
    sendMedia($ctx, UploadType::IMAGE, 'MAX_IMAGE_PATH', 'MAX_IMAGE_MIME', 'image/jpeg');
});
$dp->onCommand('/video', static function (Context $ctx): void {
    sendMedia($ctx, UploadType::VIDEO, 'MAX_VIDEO_PATH', 'MAX_VIDEO_MIME', 'video/mp4');
});
$dp->onCommand('/audio', static function (Context $ctx): void {
    sendMedia($ctx, UploadType::AUDIO, 'MAX_AUDIO_PATH', 'MAX_AUDIO_MIME', 'audio/mpeg');
});
$dp->onCommand('/file', static function (Context $ctx): void {
    sendMedia($ctx, UploadType::FILE, 'MAX_FILE_PATH', 'MAX_FILE_MIME', 'application/octet-stream');
});

$dp->onCommand('/start', static function (Context $ctx): void {
    if ($ctx->update->message !== null) {
        $ctx->bot->sendTextToChat(
            $ctx->update->message->chatId(),
            'Use /image, /video, /audio, or /file after setting the matching MAX_*_PATH env var.'
        );
    }
});

$dp->startPolling();

function sendMedia(Context $ctx, string $type, string $pathEnv, string $mimeEnv, string $defaultMime): void
{
    if ($ctx->update->message === null) {
        return;
    }

    $path = getenv($pathEnv);
    if ($path === false || $path === '') {
        $ctx->bot->sendTextToChat(
            $ctx->update->message->chatId(),
            "Set {$pathEnv} to a local file path and run this command again."
        );
        return;
    }

    $filename = basename($path) ?: 'upload.bin';
    $mime = getenv($mimeEnv);
    $mime = $mime !== false && $mime !== '' ? $mime : $defaultMime;
    $text = "Sent file from {$pathEnv}";
    $chatId = $ctx->update->message->chatId();

    switch ($type) {
        case UploadType::IMAGE:
            $ctx->bot->sendImageToChat($chatId, $path, $filename, $mime, $text);
            break;
        case UploadType::VIDEO:
            $ctx->bot->sendVideoToChat($chatId, $path, $filename, $mime, $text);
            break;
        case UploadType::AUDIO:
            $ctx->bot->sendAudioToChat($chatId, $path, $filename, $mime, $text);
            break;
        default:
            $ctx->bot->sendFileToChat($chatId, $path, $filename, $mime, $text);
    }
}
