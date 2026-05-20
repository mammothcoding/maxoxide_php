<?php

/**
 * Interactive live API test harness for a real Max bot.
 *
 * Run:
 *   php examples/live_api_test.php
 *
 * At startup it prompts for language, bot token and optional settings.
 * Then it walks through all API methods, recording PASS / FAIL / SKIP for each.
 */

declare(strict_types=1);

// Support both installed (composer) and direct source-tree runs.
$autoload  = __DIR__ . '/../vendor/autoload.php';
$bootstrap = __DIR__ . '/../bootstrap.php';
if (file_exists($autoload)) {
    require $autoload;
} elseif (file_exists($bootstrap)) {
    require $bootstrap;
} else {
    fwrite(STDERR, "Cannot find vendor/autoload.php or bootstrap.php.\n");
    fwrite(STDERR, "Run `composer install` or use bootstrap.php from the source root.\n");
    exit(1);
}

use Maxoxide\AnswerCallbackBody;
use Maxoxide\Bot;
use Maxoxide\BotCommand;
use Maxoxide\Button;
use Maxoxide\ChatAdmin;
use Maxoxide\ChatAdminPermission;
use Maxoxide\EditChatBody;
use Maxoxide\KeyboardPayload;
use Maxoxide\MaxException;
use Maxoxide\MessageFormat;
use Maxoxide\NewAttachment;
use Maxoxide\NewMessageBody;
use Maxoxide\PinMessageBody;
use Maxoxide\RemoveMemberOptions;
use Maxoxide\SendMessageOptions;
use Maxoxide\SenderAction;
use Maxoxide\SubscribeBody;
use Maxoxide\Update;
use Maxoxide\UploadType;

// ─────────────────────────────────────────────────────────────────────────────
// Timing constants (seconds)
// ─────────────────────────────────────────────────────────────────────────────

const PRIVATE_WAIT_SECS    = 180;
const GROUP_WAIT_SECS      = 240;
const MANUAL_WAIT_SECS     = 120;
const WAIT_PROMPT_CHUNK    = 15;
const TRANSPORT_LONG_POLLING = 'long_polling';
const TRANSPORT_WEBHOOK = 'webhook';
const MAX_WEBHOOK_HEADER_BYTES = 65536;
const MAX_WEBHOOK_BODY_BYTES = 1048576;

// ─────────────────────────────────────────────────────────────────────────────
// i18n helper
// ─────────────────────────────────────────────────────────────────────────────

function tr(string $lang, string $en, string $ru): string
{
    return $lang === 'ru' ? $ru : $en;
}

// ─────────────────────────────────────────────────────────────────────────────
// Terminal I/O
// ─────────────────────────────────────────────────────────────────────────────

function prompt(string $label): string
{
    echo $label . ': ';
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function promptRequired(string $lang, string $label): string
{
    while (true) {
        $value = prompt($label);
        if ($value !== '') {
            return $value;
        }
        echo tr($lang, 'Value is required.', 'Значение обязательно.') . "\n";
    }
}

function promptOptional(string $label): ?string
{
    $value = prompt($label);
    return $value !== '' ? $value : null;
}

function promptOptionalInt(string $lang, string $label): ?int
{
    while (true) {
        $value = prompt($label);
        if ($value === '') {
            return null;
        }
        if (ctype_digit(ltrim($value, '-')) && is_numeric($value)) {
            return (int) $value;
        }
        echo tr($lang, 'Expected an integer chat_id/user_id.', 'Ожидался целочисленный chat_id/user_id.') . "\n";
    }
}

function promptInt(string $lang, string $label, int $default): int
{
    while (true) {
        $value = prompt("{$label} [{$default}]");
        if ($value === '') {
            return $default;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        echo tr($lang, 'Expected an integer.', 'Ожидалось целое число.') . "\n";
    }
}

function confirm(string $lang, string $question, bool $defaultYes): bool
{
    $suffix = $defaultYes ? '[Y/n]' : '[y/N]';
    $value  = prompt("{$question} {$suffix}");
    if ($value === '') {
        return $defaultYes;
    }
    $v = strtolower($value);
    return in_array($v, ['y', 'yes', 'да', 'д', 'ага'], true);
}

function promptTransport(string $lang): string
{
    while (true) {
        $value = strtolower(prompt(tr(
            $lang,
            'Update transport [long_polling/webhook] [long_polling]',
            'Транспорт updates [long_polling/webhook] [long_polling]'
        )));
        if ($value === '' || in_array($value, ['long', 'long_polling', 'polling', 'lp'], true)) {
            return TRANSPORT_LONG_POLLING;
        }
        if (in_array($value, ['webhook', 'web', 'wh', 'вебхук'], true)) {
            return TRANSPORT_WEBHOOK;
        }

        echo tr($lang, 'Expected `long_polling` or `webhook`.', 'Ожидалось `long_polling` или `webhook`.') . "\n";
    }
}

function typedConfirmation(string $question, string $expected): bool
{
    return prompt($question) === $expected;
}

function printSection(string $title): void
{
    echo "\n=== {$title} ===\n";
}

function printCase(string $name): void
{
    echo "\n-> {$name}\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// Report
// ─────────────────────────────────────────────────────────────────────────────

class Report
{
    private array $records = [];

    public function pass(string $name, string $detail): void
    {
        $this->records[] = ['outcome' => 'PASS', 'name' => $name, 'detail' => $detail];
    }

    public function fail(string $name, string $detail): void
    {
        $this->records[] = ['outcome' => 'FAIL', 'name' => $name, 'detail' => $detail];
    }

    public function skip(string $name, string $detail): void
    {
        $this->records[] = ['outcome' => 'SKIP', 'name' => $name, 'detail' => $detail];
    }

    public function skipMany(array $names, string $reason): void
    {
        foreach ($names as $name) {
            $this->skip($name, $reason);
        }
    }

    public function printSummary(string $lang): void
    {
        printSection(tr($lang, 'Summary', 'Сводка'));

        $passed  = count(array_filter($this->records, fn($r) => $r['outcome'] === 'PASS'));
        $failed  = count(array_filter($this->records, fn($r) => $r['outcome'] === 'FAIL'));
        $skipped = count(array_filter($this->records, fn($r) => $r['outcome'] === 'SKIP'));

        if ($lang === 'ru') {
            echo "Успешно: {$passed}\nПровалено: {$failed}\nПропущено: {$skipped}\n";
        } else {
            echo "Passed: {$passed}\nFailed: {$failed}\nSkipped: {$skipped}\n";
        }

        foreach ($this->records as $r) {
            echo "[{$r['outcome']}] {$r['name']}: {$r['detail']}\n";
        }
    }
}

/** Minimal local HTTP receiver used by webhook transport mode. */
class LocalWebhookReceiver
{
    /** @var resource */
    private $server;
    private ?string $secret;

    public function __construct(string $listenAddr, ?string $secret)
    {
        $address = strpos($listenAddr, '://') === false ? "tcp://{$listenAddr}" : $listenAddr;
        $server = @stream_socket_server($address, $errno, $errstr);
        if ($server === false) {
            throw new RuntimeException("Failed to bind local webhook receiver on {$listenAddr}: {$errstr}", $errno);
        }
        stream_set_blocking($server, false);

        $this->server = $server;
        $this->secret = $secret;
    }

    /** Return the local socket name. */
    public function localAddress(): string
    {
        $name = stream_socket_get_name($this->server, false);

        return $name !== false ? $name : 'unknown';
    }

    /** Accept one webhook POST and return raw update JSON when available. */
    public function acceptRawUpdate(int $timeoutSecs): ?array
    {
        $rawUpdate = null;
        $read = [$this->server];
        $write = [];
        $except = [];
        $selected = @stream_select($read, $write, $except, max(0, $timeoutSecs));
        if ($selected > 0) {
            $connection = @stream_socket_accept($this->server, 0);
            if ($connection !== false) {
                stream_set_timeout($connection, 5);
                try {
                    $request = $this->readRequest($connection);
                    $status = $this->statusForRequest($request);
                    if ($status === 200) {
                        $decoded = json_decode($request['body'], true);
                        if (is_array($decoded)) {
                            $rawUpdate = $decoded;
                        }
                    }
                    $this->writeResponse($connection, $status);
                } catch (Throwable $e) {
                    fwrite(STDERR, "[harness] webhook receiver error: {$e->getMessage()}\n");
                    $this->writeResponse($connection, 200);
                }
                fclose($connection);
            }
        }

        return $rawUpdate;
    }

    /** Read a minimal HTTP request from a stream socket. */
    private function readRequest($connection): array
    {
        $buffer = '';
        $headerEnd = null;
        while ($headerEnd === null) {
            $chunk = fread($connection, 4096);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('connection closed before HTTP headers were complete');
            }
            $buffer .= $chunk;
            $headerEnd = $this->findHeaderEnd($buffer);
            if (strlen($buffer) > MAX_WEBHOOK_HEADER_BYTES) {
                throw new RuntimeException('webhook HTTP headers are too large');
            }
        }

        [$headerPos, $delimiterLen] = $headerEnd;
        $headersText = substr($buffer, 0, $headerPos);
        $lines = preg_split('/\r?\n/', $headersText) ?: [];
        $requestLine = array_shift($lines) ?? '';
        $requestParts = preg_split('/\s+/', trim($requestLine)) ?: [];
        $method = strtoupper($requestParts[0] ?? '');
        $headers = [];
        $contentLength = 0;
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                $headers[$name] = $value;
                if ($name === 'content-length') {
                    $contentLength = (int) $value;
                }
            }
        }
        if ($contentLength > MAX_WEBHOOK_BODY_BYTES) {
            throw new RuntimeException('webhook HTTP body is too large');
        }

        $body = substr($buffer, $headerPos + $delimiterLen);
        while (strlen($body) < $contentLength) {
            $chunk = fread($connection, $contentLength - strlen($body));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('connection closed before HTTP body was complete');
            }
            $body .= $chunk;
        }

        return [
            'method' => $method,
            'headers' => $headers,
            'body' => substr($body, 0, $contentLength),
        ];
    }

    /** Determine where HTTP headers end. */
    private function findHeaderEnd(string $buffer): ?array
    {
        $pos = strpos($buffer, "\r\n\r\n");
        if ($pos !== false) {
            return [$pos, 4];
        }
        $pos = strpos($buffer, "\n\n");
        if ($pos !== false) {
            return [$pos, 2];
        }

        return null;
    }

    /** Return HTTP status for the request before JSON parsing. */
    private function statusForRequest(array $request): int
    {
        $status = 200;
        if ($request['method'] !== 'POST') {
            $status = 405;
        } elseif (!$this->secretMatches($request['headers'])) {
            $status = 401;
        }

        return $status;
    }

    /** Verify the MAX webhook secret header. */
    private function secretMatches(array $headers): bool
    {
        $matches = true;
        if ($this->secret !== null) {
            $matches = isset($headers['x-max-bot-api-secret']) && $headers['x-max-bot-api-secret'] === $this->secret;
        }

        return $matches;
    }

    /** Write a small HTTP response. */
    private function writeResponse($connection, int $status): void
    {
        $reasons = [200 => 'OK', 401 => 'Unauthorized', 405 => 'Method Not Allowed', 503 => 'Service Unavailable'];
        $reason = $reasons[$status] ?? 'Error';
        $body = $reason;
        $response = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: text/plain\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
        fwrite($connection, $response);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Harness
// ─────────────────────────────────────────────────────────────────────────────

class Harness
{
    public Bot    $bot;
    private ?int  $marker       = null;
    private int   $requestDelay;   // microseconds
    private int   $pollTimeout;    // seconds
    private string $lang;
    private string $transport;
    private ?LocalWebhookReceiver $webhookReceiver;

    public function __construct(
        Bot $bot,
        int $requestDelayMs,
        int $pollTimeoutSecs,
        string $lang,
        string $transport = TRANSPORT_LONG_POLLING,
        ?LocalWebhookReceiver $webhookReceiver = null
    )
    {
        $this->bot          = $bot;
        $this->requestDelay = $requestDelayMs * 1000;
        $this->pollTimeout  = $pollTimeoutSecs;
        $this->lang         = $lang;
        $this->transport    = $transport;
        $this->webhookReceiver = $webhookReceiver;
    }

    /** Execute an API call, record PASS/FAIL, return result or null on failure. */
    public function apiCase(Report $report, string $name, callable $operation)
    {
        $this->pause();
        printCase($name);
        try {
            $result = $operation($this->bot);
            $report->pass($name, tr($this->lang, 'ok', 'ok'));
            echo "   PASS\n";
            return $result;
        } catch (MaxException $e) {
            $detail = $e->getMessage();
            $report->fail($name, $detail);
            echo "   FAIL: {$detail}\n";
            return null;
        } catch (\Throwable $e) {
            $detail = $e->getMessage();
            $report->fail($name, $detail);
            echo "   FAIL: {$detail}\n";
            return null;
        }
    }

    /**
     * Poll for an update matching $predicate within $timeoutSecs seconds.
     * Every WAIT_PROMPT_CHUNK seconds without a match it asks the tester:
     *   Enter = keep waiting | skip = mark SKIP | fail = mark FAIL
     */
    public function waitCase(
        Report $report,
        string $name,
        string $instructions,
        int $timeoutSecs,
        callable $predicate
    ): ?Update {
        printCase($name);
        echo "   {$instructions}\n";

        $deadline = time() + $timeoutSecs;

        while (true) {
            $remaining = $deadline - time();
            if ($remaining <= 0) {
                $detail = tr($this->lang, 'timeout while waiting for update', 'таймаут ожидания обновления');
                $report->fail($name, $detail);
                echo "   FAIL: {$detail}\n";
                return null;
            }

            $chunk  = min($remaining, WAIT_PROMPT_CHUNK);
            $update = $this->waitForUpdateChunk($chunk, $predicate);

            if ($update !== null) {
                $report->pass($name, tr($this->lang, 'event received', 'событие получено'));
                echo "   PASS\n";
                printUpdateDetails($this->lang, $update);
                return $update;
            }

            // Chunk elapsed without a match — ask the tester what to do.
            $decision = $this->promptWaitDecision();
            if ($decision === 'skip') {
                $detail = tr($this->lang, 'tester skipped this waiting step', 'тестер пропустил этот шаг ожидания');
                $report->skip($name, $detail);
                echo "   SKIP: {$detail}\n";
                return null;
            }
            if ($decision === 'fail') {
                $detail = tr($this->lang, 'tester marked this waiting step as failed', 'тестер пометил этот шаг ожидания как проваленный');
                $report->fail($name, $detail);
                echo "   FAIL: {$detail}\n";
                return null;
            }
            // 'continue' → keep looping
        }
    }

    /** Drain all pending updates so we start from a clean marker. */
    public function flushUpdates(): int
    {
        if ($this->transport === TRANSPORT_WEBHOOK) {
            return 0;
        }

        $drained = 0;
        while (true) {
            $this->pause();
            $resp = $this->bot->getUpdatesRaw($this->marker, 1, 100);
            if ($resp->marker !== null) {
                $this->marker = $resp->marker;
            }
            $drained += count($resp->updates);
            if (count($resp->updates) === 0) {
                return $drained;
            }
        }
    }

    /** Wait for one matching update using the configured transport. */
    private function waitForUpdateChunk(int $timeoutSecs, callable $predicate): ?Update
    {
        $update = $this->transport === TRANSPORT_WEBHOOK
            ? $this->waitForWebhookUpdateChunk($timeoutSecs, $predicate)
            : $this->waitForLongPollingUpdateChunk($timeoutSecs, $predicate);

        return $update;
    }

    /** Wait for one matching update through GET /updates. */
    private function waitForLongPollingUpdateChunk(int $timeoutSecs, callable $predicate): ?Update
    {
        $deadline = time() + $timeoutSecs;
        while (time() < $deadline) {
            $this->pause();
            $remaining = $deadline - time();
            $pollSecs  = max(1, min($remaining, $this->pollTimeout));
            try {
                $resp = $this->bot->getUpdatesRaw($this->marker, $pollSecs, 100);
            } catch (\Throwable $e) {
                fwrite(STDERR, "[harness] poll error: {$e->getMessage()}\n");
                sleep(2);
                continue;
            }
            if ($resp->marker !== null) {
                $this->marker = $resp->marker;
            }
            foreach ($resp->updates as $rawUpdate) {
                $update = Update::fromArray($rawUpdate);
                if ($predicate($update)) {
                    return $update;
                }
            }
        }
        return null;
    }

    /** Wait for one matching update from the local webhook receiver. */
    private function waitForWebhookUpdateChunk(int $timeoutSecs, callable $predicate): ?Update
    {
        if ($this->webhookReceiver === null) {
            fwrite(STDERR, "[harness] webhook transport selected, but receiver is not configured\n");
            return null;
        }

        $deadline = time() + $timeoutSecs;
        while (time() < $deadline) {
            $remaining = max(1, min($deadline - time(), $this->pollTimeout));
            $rawUpdate = $this->webhookReceiver->acceptRawUpdate($remaining);
            if ($rawUpdate !== null) {
                $update = Update::fromArray($rawUpdate);
                if ($predicate($update)) {
                    return $update;
                }
                echo '   ' . tr($this->lang, 'non-matching webhook update', 'неподходящий webhook update')
                    . ': ' . ($update->updateType() ?? 'unknown') . "\n";
            }
        }

        return null;
    }

    private function promptWaitDecision(): string
    {
        while (true) {
            $answer = prompt(tr(
                $this->lang,
                'No matching update yet. Press Enter to continue waiting, type `skip` to skip, or `fail` to mark this step as failed',
                'Подходящее обновление пока не пришло. Нажмите Enter, чтобы ждать дальше, введите `skip` для пропуска или `fail`, чтобы пометить шаг как проваленный'
            ));
            $v = strtolower(trim($answer));
            if (in_array($v, ['', 'c', 'continue', 'wait', 'ждать'], true)) {
                return 'continue';
            }
            if (in_array($v, ['s', 'skip', 'пропуск', 'пропустить'], true)) {
                return 'skip';
            }
            if (in_array($v, ['f', 'fail', 'ошибка', 'провал'], true)) {
                return 'fail';
            }
            echo tr($this->lang, 'Expected Enter, `skip`, or `fail`.', 'Ожидался Enter, `skip` или `fail`.') . "\n";
        }
    }

    public function pause(): void
    {
        if ($this->requestDelay > 0) {
            usleep($this->requestDelay);
        }
    }

    /** Return the current polling marker. */
    public function marker(): ?int
    {
        return $this->marker;
    }

    /** Update the current polling marker. */
    public function setMarker(?int $marker): void
    {
        $this->marker = $marker;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Update detail printer
// ─────────────────────────────────────────────────────────────────────────────

function printUpdateDetails(string $lang, Update $update): void
{
    if ($update->updateType() !== null) {
        echo '   update_type: ' . $update->updateType() . "\n";
    }

    switch ($update->type) {
        case 'message_callback':
            if ($update->callback !== null) {
                echo '   callback_id: ' . $update->callback->callbackId . "\n";
                echo '   ' . tr($lang, 'user_id', 'user_id') . ': ' . $update->callback->user->userId . "\n";
                if ($update->callback->payload !== null) {
                    echo '   payload: ' . $update->callback->payload . "\n";
                }
            }
            break;

        case 'message_created':
        case 'message_edited':
            if ($update->message !== null) {
                echo '   chat_id: ' . $update->message->chatId() . "\n";
                echo '   message_id: ' . $update->message->messageId() . "\n";
                if ($update->message->sender !== null) {
                    echo '   ' . tr($lang, 'user_id', 'user_id') . ': ' . $update->message->sender->userId . "\n";
                    echo '   ' . tr($lang, 'sender', 'отправитель') . ': ' . $update->message->sender->displayName() . "\n";
                }
                if ($update->message->text() !== null) {
                    echo '   ' . tr($lang, 'text', 'текст') . ': ' . $update->message->text() . "\n";
                }
                if ($update->message->url !== null) {
                    echo '   url: ' . $update->message->url . "\n";
                }
                if ($update->message->constructor !== null) {
                    echo '   constructor: ' . json_encode($update->message->constructor, JSON_UNESCAPED_UNICODE) . "\n";
                }
                if ($update->message->body->markup !== []) {
                    $kinds = array_map(static fn($m) => $m->kind(), $update->message->body->markup);
                    echo '   markup: ' . implode(',', $kinds) . "\n";
                }
                foreach ($update->message->body->attachments as $att) {
                    echo '   ' . tr($lang, 'attachment', 'вложение') . ': ' . $att->type . "\n";
                    if ($att->type === 'contact') {
                        echo '   ' . tr($lang, 'contact_name', 'имя_контакта') . ': ' . ($att->contactName ?? 'null') . "\n";
                        echo '   contact_id: ' . ($att->contactId ?? 'null') . "\n";
                        echo '   ' . tr($lang, 'phone', 'телефон') . ': ' . ($att->vcfPhone ?? 'null') . "\n";
                        echo '   hash: ' . ($att->hash ?? 'null') . "\n";
                        if ($att->maxInfo !== null) {
                            echo '   max_info.user_id: ' . $att->maxInfo->userId . "\n";
                        }
                        $phones = $att->phonesFromVcf();
                        if ($phones !== []) {
                            echo '   vcf_phones: ' . implode(',', $phones) . "\n";
                        }
                    } elseif ($att->type === 'location') {
                        echo '   ' . tr($lang, 'latitude', 'широта') . ': ' . $att->latitude
                            . ', ' . tr($lang, 'longitude', 'долгота') . ': ' . $att->longitude . "\n";
                    } elseif ($att->type === 'share') {
                        echo '   share_url: ' . ($att->url ?? 'null') . "\n";
                        echo '   share_title: ' . ($att->title ?? 'null') . "\n";
                    } elseif ($att->type === 'data') {
                        echo '   data: ' . ($att->data ?? 'null') . "\n";
                    } elseif (in_array($att->type, ['image', 'video', 'audio', 'file'], true)) {
                        if ($att->url !== null) {
                            echo "   attachment_url: {$att->url}\n";
                        }
                        if ($att->token !== null) {
                            echo "   attachment_token: {$att->token}\n";
                        }
                        if ($att->filename !== null) {
                            echo "   filename: {$att->filename}\n";
                        }
                    } elseif ($att->raw !== []) {
                        echo '   attachment_raw: ' . json_encode($att->raw, JSON_UNESCAPED_UNICODE) . "\n";
                    }
                }
            }
            break;

        case 'message_edited_missing':
            echo '   ' . tr($lang, 'message_edited without message payload', 'message_edited без message payload') . "\n";
            break;

        case 'message_chat_created':
            if ($update->chat !== null) {
                echo '   chat_id: ' . $update->chat->chatId . "\n";
                echo '   chat_type: ' . $update->chat->type . "\n";
            }
            echo '   message_id: ' . ($update->messageId ?? 'null') . "\n";
            echo '   start_payload: ' . ($update->startPayload ?? 'null') . "\n";
            break;

        case 'bot_stopped':
        case 'dialog_cleared':
        case 'dialog_muted':
        case 'dialog_unmuted':
        case 'dialog_removed':
            echo '   chat_id: ' . ($update->chatId ?? 'null') . "\n";
            if ($update->user !== null) {
                echo '   user_id: ' . $update->user->userId . "\n";
            }
            if ($update->mutedUntil !== null) {
                echo '   muted_until: ' . $update->mutedUntil . "\n";
            }
            break;

        default:
            if ($update->raw() !== null) {
                echo '   raw_update: ' . json_encode($update->raw(), JSON_UNESCAPED_UNICODE) . "\n";
            }
            break;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function messageHasAttachment(Update $update, callable $predicate): bool
{
    if ($update->message === null) {
        return false;
    }
    foreach ($update->message->body->attachments as $att) {
        if ($predicate($att)) {
            return true;
        }
    }
    return false;
}

function looksLikeClientMapCard(Update $update): bool
{
    if ($update->message === null) {
        return false;
    }

    $parts = [];
    if ($update->message->text() !== null) {
        $parts[] = $update->message->text();
    }
    if ($update->message->url !== null) {
        $parts[] = $update->message->url;
    }
    if ($update->message->constructor !== null) {
        $parts[] = json_encode($update->message->constructor, JSON_UNESCAPED_UNICODE);
    }
    foreach ($update->message->body->attachments as $attachment) {
        $parts[] = json_encode($attachment->raw !== [] ? $attachment->raw : $attachment->payload, JSON_UNESCAPED_UNICODE);
    }

    $haystack = strtolower(implode("\n", array_filter($parts, static fn($part) => is_string($part) && $part !== '')));

    return strpos($haystack, 'yandex') !== false
        || strpos($haystack, 'яндекс') !== false
        || strpos($haystack, 'Яндекс') !== false
        || strpos($haystack, 'maps') !== false
        || strpos($haystack, 'yandex.ru/maps') !== false;
}

function extractVideoToken($message): ?string
{
    foreach ($message->body->attachments as $attachment) {
        if ($attachment->type === 'video' && $attachment->token !== null) {
            return $attachment->token;
        }
    }

    return null;
}

function extractContactPhone(Update $update): ?string
{
    if ($update->message === null) {
        return null;
    }
    foreach ($update->message->body->attachments as $att) {
        if ($att->type === 'contact') {
            if ($att->vcfPhone !== null && $att->vcfPhone !== '') {
                return $att->vcfPhone;
            }
            $phones = $att->phonesFromVcf();
            if ($phones !== []) {
                return $phones[0];
            }
        }
    }
    return null;
}

function firstContactAttachment(Update $update)
{
    if ($update->message === null) {
        return null;
    }
    foreach ($update->message->body->attachments as $att) {
        if ($att->type === 'contact') {
            return $att;
        }
    }

    return null;
}

function messageMarkupKinds($message): ?string
{
    if ($message === null || $message->body->markup === []) {
        return null;
    }

    return implode(',', array_map(static fn($markup) => $markup->kind(), $message->body->markup));
}

function extractSenderUserId(Update $update): ?int
{
    if (in_array($update->type, ['message_created', 'message_edited'], true)) {
        return $update->message?->sender?->userId ?? null;
    }
    if ($update->type === 'message_callback') {
        return $update->callback?->user->userId ?? null;
    }
    return $update->user?->userId ?? null;
}

function confirmCase(string $lang, Report $report, string $name, string $question): void
{
    if (confirm($lang, $question, true)) {
        $report->pass($name, tr($lang, 'tester confirmed', 'тестер подтвердил'));
    } else {
        $report->skip($name, tr($lang, 'tester did not confirm', 'тестер не подтвердил'));
    }
}

function printKnownChats(array $chats, string $lang): void
{
    if (empty($chats)) {
        echo tr($lang, 'No group chats returned.', 'Групповые чаты не были возвращены.') . "\n";
        return;
    }
    foreach ($chats as $chat) {
        $title = $chat->title ?? tr($lang, '(no title)', '(без названия)');
        echo "  - {$chat->chatId} [{$chat->type}] {$title}\n";
    }
}

function printChatMembers(array $members, string $lang): void
{
    if (empty($members)) {
        echo tr($lang, 'No chat members were returned.', 'Участники чата не были возвращены.') . "\n";
        return;
    }
    echo tr($lang, 'Chat members returned by bot.get_members:', 'Участники, возвращённые bot.get_members:') . "\n";
    foreach ($members as $m) {
        echo "  - {$m->userId} {$m->displayName()}\n";
    }
}

function printBotMembership($member, string $lang): void
{
    echo tr($lang, 'Bot membership:', 'Членство бота:') . "\n";
    echo "  - user_id={$member->userId}, admin=" . (($member->isAdmin ?? false) ? 'true' : 'false')
        . ', owner=' . (($member->isOwner ?? false) ? 'true' : 'false') . "\n";
    if ($member->permissions !== null) {
        echo '  - permissions=' . implode(',', $member->permissions) . "\n";
    }
}

function filenameFromPath(string $path, string $fallback): string
{
    $filename = basename($path);

    return $filename !== '' ? $filename : $fallback;
}

function mimeForPath(string $path, string $fallback): string
{
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
    ];

    return $map[$ext] ?? $fallback;
}

function memberCanAddAdmins($member): bool
{
    if ($member === null) {
        return false;
    }
    if ($member->isOwner === true) {
        return true;
    }

    return $member->permissions !== null && in_array(ChatAdminPermission::ADD_ADMINS, $member->permissions, true);
}

function adminProbePermissions($member): array
{
    if ($member === null || $member->isOwner === true) {
        return [ChatAdminPermission::READ_ALL_MESSAGES];
    }

    $permissions = $member->permissions ?? [];
    foreach ([ChatAdminPermission::READ_ALL_MESSAGES, ChatAdminPermission::WRITE, ChatAdminPermission::ADD_REMOVE_MEMBERS] as $preferred) {
        if (in_array($preferred, $permissions, true)) {
            return [$preferred];
        }
    }
    foreach ($permissions as $permission) {
        if ($permission !== ChatAdminPermission::ADD_ADMINS) {
            return [$permission];
        }
    }

    return [ChatAdminPermission::READ_ALL_MESSAGES];
}

function prepareUploadFile(?string $path): string
{
    if ($path !== null) {
        return $path;
    }
    $tmp = sys_get_temp_dir() . '/maxoxide-live-upload.txt';
    file_put_contents($tmp, "maxoxide live upload_file payload\n");
    return $tmp;
}

function printSubscriptions(array $subscriptions, string $lang): void
{
    if ($subscriptions === []) {
        echo tr($lang, 'No active webhook subscriptions.', 'Активных webhook subscriptions нет.') . "\n";
        return;
    }

    echo tr($lang, 'Active webhook subscriptions:', 'Активные webhook subscriptions:') . "\n";
    foreach ($subscriptions as $subscription) {
        $types = $subscription->updateTypes !== null ? implode(',', $subscription->updateTypes) : '*';
        echo "  - {$subscription->url} types={$types}\n";
    }
}

function prepareLongPollingPhase(Harness $harness, Report $report, array $cfg): ?array
{
    $lang = $cfg['lang'];
    $subscriptions = $harness->apiCase($report, 'bot.get_subscriptions(pre_poll)', fn(Bot $b) => $b->getSubscriptions());
    if ($subscriptions === null) {
        $report->skip('manual.long_polling_subscription_check', tr($lang,
            'could not inspect active webhook subscriptions',
            'не удалось проверить активные webhook subscriptions'
        ));
        return [];
    }

    if ($subscriptions->subscriptions === []) {
        $report->pass('manual.long_polling_subscription_check', tr($lang,
            'no active webhook subscriptions',
            'активных webhook subscriptions нет'
        ));
        return [];
    }

    printSubscriptions($subscriptions->subscriptions, $lang);
    echo tr($lang,
        'Active webhook subscriptions disable long polling, so manual update waits will not receive `/live`.',
        'Активные webhook subscriptions отключают long polling, поэтому ручные ожидания updates не получат `/live`.'
    ) . "\n";
    if ($cfg['webhook_secret'] === null) {
        echo tr($lang,
            'MAX does not return webhook secrets from get_subscriptions. Restoration will subscribe without a secret.',
            'MAX не возвращает webhook secrets из get_subscriptions. Восстановление подпишет webhook без secret.'
        ) . "\n";
        if (!confirm($lang, tr($lang,
            'Continue and restore disabled webhooks without a secret?',
            'Продолжить и восстановить отключённые webhooks без secret?'
        ), false)) {
            $report->fail('manual.long_polling_subscription_check', tr($lang,
                'active webhooks were left enabled because webhook secret for restoration was not provided',
                'активные webhooks оставлены включёнными, потому что webhook secret для восстановления не был указан'
            ));
            return null;
        }
    }

    if (!confirm($lang, tr($lang,
        'Unsubscribe all active webhooks now before long-polling tests? Type `y` to continue the live run.',
        'Отписать все активные webhooks сейчас перед long-polling тестами? Введите `y`, чтобы продолжить live-прогон.'
    ), false)) {
        $report->fail('manual.long_polling_subscription_check', tr($lang,
            'active webhook subscriptions were left enabled; long polling would not receive updates',
            'активные webhook subscriptions оставлены включёнными; long polling не будет получать updates'
        ));
        return null;
    }

    $disabled = [];
    foreach ($subscriptions->subscriptions as $subscription) {
        $removed = $harness->apiCase($report, 'bot.unsubscribe(pre_poll)', fn(Bot $b) => $b->unsubscribe($subscription->url));
        if ($removed !== null) {
            $disabled[] = $subscription;
        }
    }
    if (count($disabled) !== count($subscriptions->subscriptions)) {
        $report->fail('manual.long_polling_subscription_check', tr($lang,
            'not all active webhook subscriptions were removed; restoring removed subscriptions and stopping before long polling',
            'не все active webhook subscriptions были удалены; восстанавливаем удалённые subscriptions и останавливаемся перед long polling'
        ));
        restoreDisabledWebhooks($harness, $report, $cfg, $disabled);
        return null;
    }

    $report->pass('manual.long_polling_subscription_check', tr($lang,
        'active webhook subscriptions were removed before long polling',
        'активные webhook subscriptions удалены перед long polling'
    ));

    return $disabled;
}

function restoreDisabledWebhooks(Harness $harness, Report $report, array $cfg, array $disabledSubscriptions): void
{
    if ($disabledSubscriptions === []) {
        return;
    }

    printSection(tr($cfg['lang'], 'Webhook Restore', 'Восстановление webhook'));
    foreach ($disabledSubscriptions as $subscription) {
        $harness->apiCase($report, 'bot.subscribe(restore_pre_poll)', function (Bot $b) use ($subscription, $cfg) {
            $body = new SubscribeBody($subscription->url);
            $body->updateTypes = $subscription->updateTypes;
            $body->version = $subscription->version;
            $body->secret = $cfg['webhook_secret'];

            return $b->subscribe($body);
        });
    }
}

function prepareWebhookPhase(Harness $harness, Report $report, array $cfg): bool
{
    $lang = $cfg['lang'];
    if ($cfg['webhook_register']) {
        if ($cfg['webhook_url'] === null) {
            $report->fail('bot.subscribe(webhook_transport)', tr($lang,
                'webhook registration was requested, but webhook URL is empty',
                'запрошена регистрация webhook, но webhook URL пустой'
            ));
            return false;
        }

        $subscribed = $harness->apiCase($report, 'bot.subscribe(webhook_transport)', function (Bot $b) use ($cfg) {
            $body = new SubscribeBody($cfg['webhook_url']);
            $body->secret = $cfg['webhook_secret'];

            return $b->subscribe($body);
        });
        if ($subscribed === null) {
            return false;
        }
    } else {
        $report->skip('bot.subscribe(webhook_transport)', tr($lang,
            'tester chose to use an already configured webhook subscription',
            'тестер выбрал уже настроенную webhook subscription'
        ));
    }

    $report->pass('manual.webhook_receiver_ready', tr($lang,
        'local webhook receiver is running; manual waits will use webhook updates',
        'локальный webhook receiver запущен; ручные ожидания будут использовать webhook updates'
    ));

    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// Configuration
// ─────────────────────────────────────────────────────────────────────────────

function promptConfig(string $lang): array
{
    printSection(tr($lang, 'Configuration', 'Конфигурация'));
    echo tr($lang, 'Secrets entered here are echoed in the terminal.', 'Секреты, введённые здесь, будут отображаться в терминале.') . "\n";
    $transport = promptTransport($lang);
    $token = promptRequired($lang, tr($lang, 'Bot token', 'Токен бота'));
    $botLink = promptOptional(tr($lang, 'Bot URL for the tester (optional)', 'URL бота для тестера (необязательно)'));
    $webhookUrlLabel = $transport === TRANSPORT_WEBHOOK
        ? tr($lang,
            'Public webhook URL for MAX (optional if already subscribed)',
            'Публичный webhook URL для MAX (необязательно, если уже подписан)'
        )
        : tr($lang,
            'Webhook URL for subscribe/unsubscribe probe (optional; active subscriptions are restored from get_subscriptions)',
            'Webhook URL для проверки subscribe/unsubscribe (необязательно; активные subscriptions восстанавливаются из get_subscriptions)'
        );
    $webhookUrl = promptOptional($webhookUrlLabel);
    $webhookSecret = promptOptional(tr(
        $lang,
        'Webhook secret for webhook mode and restoring temporarily disabled subscriptions (optional)',
        'Webhook secret для webhook-режима и восстановления временно отключённых subscriptions (необязательно)'
    ));
    $webhookListenAddr = null;
    $webhookRegister = false;
    if ($transport === TRANSPORT_WEBHOOK) {
        $listen = prompt(tr($lang, 'Local webhook listen address [0.0.0.0:8080]', 'Локальный адрес для приёма webhook [0.0.0.0:8080]'));
        $webhookListenAddr = $listen !== '' ? $listen : '0.0.0.0:8080';
        $webhookRegister = $webhookUrl !== null && confirm($lang, tr(
            $lang,
            'Register/update this webhook subscription before manual waits?',
            'Зарегистрировать/обновить эту webhook subscription перед ручными ожиданиями?'
        ), true);
        if ($webhookUrl === null) {
            echo tr($lang,
                'Webhook URL is empty, so the harness will only receive an already configured subscription.',
                'Webhook URL пустой, поэтому harness будет только принимать уже настроенную подписку.'
            ) . "\n";
        }
    }

    return [
        'lang'           => $lang,
        'transport'      => $transport,
        'token'          => $token,
        'bot_link'       => $botLink,
        'webhook_url'    => $webhookUrl,
        'webhook_secret' => $webhookSecret,
        'webhook_listen_addr' => $webhookListenAddr,
        'webhook_register' => $webhookRegister,
        'upload_path'    => promptOptional(tr($lang, 'Path to a local file for bot.upload_file (optional)', 'Путь к локальному файлу для bot.upload_file (необязательно)')),
        'upload_image_path' => promptOptional(tr($lang, 'Path to an image for send_image_to_chat (optional)', 'Путь к изображению для send_image_to_chat (необязательно)')),
        'upload_video_path' => promptOptional(tr($lang, 'Path to a video for send_video_to_chat/get_video (optional)', 'Путь к видео для send_video_to_chat/get_video (необязательно)')),
        'upload_audio_path' => promptOptional(tr($lang, 'Path to an audio file for send_audio_to_chat (optional)', 'Путь к аудиофайлу для send_audio_to_chat (необязательно)')),
        'request_delay'  => promptInt($lang, tr($lang, 'Delay between API requests in ms', 'Задержка между API-запросами в мс'), 400),
        'http_timeout'   => promptInt($lang, tr($lang, 'HTTP timeout in seconds', 'HTTP timeout в секундах'), 15),
        'poll_timeout'   => promptInt($lang, tr($lang, 'Long polling timeout in seconds', 'Long polling timeout в секундах'), 5),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Phases
// ─────────────────────────────────────────────────────────────────────────────

function runPrivatePhase(Harness $harness, Report $report, array $cfg): array
{
    $lang = $cfg['lang'];

    printSection(tr($lang, 'Private Chat', 'Личный чат'));
    echo tr($lang, '1. Open the bot in Max.', '1. Откройте бота в Max.') . "\n";
    if ($cfg['bot_link'] !== null) {
        echo '   ' . tr($lang, 'Bot URL', 'URL бота') . ': ' . $cfg['bot_link'] . "\n";
    }
    echo tr($lang, '2. Send `/live` to the bot from a private dialog.', '2. Отправьте `/live` боту в личном диалоге.') . "\n";

    $activation = $harness->waitCase(
        $report,
        'manual.private_activation',
        tr($lang, 'Waiting for `/live` in a private chat.', 'Ожидание `/live` в личном чате.'),
        PRIVATE_WAIT_SECS,
        fn(Update $u) => $u->type === 'message_created'
            && $u->message !== null
            && $u->message->recipient->chatType === 'dialog'
            && $u->message->text() === '/live'
    );

    if ($activation === null) {
        $report->skipMany([
            'bot.get_chat(private)',        'bot.send_text_to_chat',
            'bot.send_text_to_user',        'bot.send_markdown_to_chat',
            'bot.send_markdown_to_user',    'bot.send_message_to_chat(text_body)',
            'bot.send_message_to_chat(markup_quote)', 'bot.get_message(markup_quote)',
            'manual.message_markup_returned',
            'bot.send_message_to_user(text_body)',
            'bot.send_message_to_chat_with_options(disable_link_preview)',
            'bot.send_message_to_chat(keyboard)',
            'bot.send_message_to_chat(open_app_button)',
            'manual.observe_open_app_button',
            'bot.send_message_to_chat(clipboard_button)',
            'manual.observe_clipboard_button',
            'bot.send_message_to_chat(chat_button)', 'manual.message_chat_created',
            'bot.answer_callback',          'bot.edit_message',
            'bot.get_message',              'bot.get_messages',
            'bot.get_messages_by_ids',      'bot.delete_message',
        ], tr($lang, 'private chat activation was not completed', 'активация личного чата не была завершена'));
        return ['chat_id' => null, 'user_id' => null];
    }

    $privateChatId = $activation->message->chatId();
    $privateUserId = $activation->message->sender?->userId ?? null;
    echo tr($lang, 'Private chat id', 'ID личного чата') . ": {$privateChatId}\n";

    $harness->apiCase($report, 'bot.get_chat(private)', fn(Bot $b) => $b->getChat($privateChatId));

    $plainMessage = $harness->apiCase($report, 'bot.send_text_to_chat',
        fn(Bot $b) => $b->sendTextToChat($privateChatId, 'maxoxide live test: plain text message'));

    if ($privateUserId !== null) {
        $harness->apiCase($report, 'bot.send_text_to_user',
            fn(Bot $b) => $b->sendTextToUser($privateUserId, 'maxoxide live test: send_text_to_user'));
    } else {
        $report->skip('bot.send_text_to_user', tr($lang, 'sender.user_id is missing', 'sender.user_id отсутствует'));
    }

    $harness->apiCase($report, 'bot.send_markdown_to_chat',
        fn(Bot $b) => $b->sendMarkdownToChat($privateChatId, '*maxoxide live test*: `send_markdown_to_chat`'));

    $markupMessage = $harness->apiCase($report, 'bot.send_message_to_chat(markup_quote)',
        fn(Bot $b) => $b->sendMessageToChat(
            $privateChatId,
            NewMessageBody::text("# maxoxide markup probe\n\n**strong**, _emphasis_, ~~strike~~, `code`, [docs](https://dev.max.ru/)")
                ->withFormat(MessageFormat::MARKDOWN)
        ));
    if ($markupMessage !== null) {
        $fetchedMarkupMessage = $harness->apiCase($report, 'bot.get_message(markup_quote)',
            fn(Bot $b) => $b->getMessage($markupMessage->messageId()));
        $markupSource = $fetchedMarkupMessage ?? $markupMessage;
        $kinds = messageMarkupKinds($markupSource);
        if ($kinds !== null) {
            $report->pass('manual.message_markup_returned', "markup kinds: {$kinds}");
        } else {
            $report->skip('manual.message_markup_returned', tr($lang,
                'MAX did not return markup for the bot formatted message; treating this as a platform limitation',
                'MAX не вернул markup для форматированного сообщения бота; шаг помечен как платформенное ограничение'
            ));
        }
    } else {
        $report->skip('bot.get_message(markup_quote)', tr($lang, 'formatted markup message was not sent', 'форматированное сообщение с markup не было отправлено'));
        $report->skip('manual.message_markup_returned', tr($lang, 'formatted markup message was not sent', 'форматированное сообщение с markup не было отправлено'));
    }

    if ($privateUserId !== null) {
        $harness->apiCase($report, 'bot.send_markdown_to_user',
            fn(Bot $b) => $b->sendMarkdownToUser($privateUserId, '*maxoxide live test*: `send_markdown_to_user`'));
    } else {
        $report->skip('bot.send_markdown_to_user', tr($lang, 'sender.user_id is missing', 'sender.user_id отсутствует'));
    }

    $harness->apiCase($report, 'bot.send_message_to_chat(text_body)',
        fn(Bot $b) => $b->sendMessageToChat($privateChatId, NewMessageBody::text('maxoxide live test: send_message_to_chat')));

    if ($privateUserId !== null) {
        $harness->apiCase($report, 'bot.send_message_to_user(text_body)',
            fn(Bot $b) => $b->sendMessageToUser($privateUserId, NewMessageBody::text('maxoxide live test: send_message_to_user')));
    } else {
        $report->skip('bot.send_message_to_user(text_body)', tr($lang, 'sender.user_id is missing', 'sender.user_id отсутствует'));
    }

    $harness->apiCase($report, 'bot.send_message_to_chat_with_options(disable_link_preview)',
        fn(Bot $b) => $b->sendMessageToChatWithOptions(
            $privateChatId,
            NewMessageBody::text('https://max.ru'),
            SendMessageOptions::disableLinkPreview(true)
        ));

    // ── Keyboard ──────────────────────────────────────────────────────────────
    $callbackBtnText = tr($lang, 'Confirm callback', 'Подтвердить callback');
    $msgBtnText      = tr($lang, 'live:message_button', 'live:message_button_ru');
    $contactBtnText  = tr($lang, 'Share contact', 'Поделиться контактом');
    $locationBtnText = tr($lang, 'Share location', 'Поделиться геопозицией');
    $linkBtnText     = tr($lang, 'Open docs', 'Открыть документацию');

    $keyboard = new KeyboardPayload([
        [Button::callback($callbackBtnText, 'live:callback')],
        [Button::message($msgBtnText)],
        [Button::requestContact($contactBtnText)],
        [Button::requestGeoLocation($locationBtnText)],
        [Button::link($linkBtnText, 'https://dev.max.ru/docs-api')],
    ]);

    $keyboardBody    = NewMessageBody::text(tr($lang,
        'Live test keyboard: callback, message, contact, location, link.',
        'Клавиатура live-теста: callback, сообщение, контакт, геопозиция, ссылка.'
    ))->withKeyboard($keyboard);

    $keyboardMessage = $harness->apiCase($report, 'bot.send_message_to_chat(keyboard)',
        fn(Bot $b) => $b->sendMessageToChat($privateChatId, $keyboardBody));

    if ($keyboardMessage !== null) {
        confirmCase($lang, $report, 'manual.observe_link_button',
            tr($lang, 'Is the link button visible in the sent keyboard?', 'Видна ли в отправленной клавиатуре кнопка-ссылка?'));

        $webApp = promptOptional(tr($lang,
            'Optional platform probe: enter open_app web_app value, or leave blank to skip',
            'Опциональная platform-проверка: введите значение web_app для open_app или оставьте поле пустым'
        ));
        if ($webApp !== null) {
            $openAppPayload = promptOptional(tr($lang, 'Optional open_app payload string', 'Необязательная строка payload для open_app'));
            $openAppContactId = promptOptionalInt($lang, tr($lang, 'Optional open_app contact_id', 'Необязательный contact_id для open_app'));
            $openAppKeyboard = new KeyboardPayload([
                [Button::openAppFull(tr($lang, 'Open app', 'Открыть app'), $webApp, $openAppPayload, $openAppContactId)],
            ]);
            $openAppBody = NewMessageBody::text(tr($lang,
                'MAX platform probe: open_app button.',
                'Проверка платформы MAX: open_app-кнопка.'
            ))->withKeyboard($openAppKeyboard);
            $openAppMessage = $harness->apiCase($report, 'bot.send_message_to_chat(open_app_button)',
                fn(Bot $b) => $b->sendMessageToChat($privateChatId, $openAppBody));
            if ($openAppMessage !== null) {
                confirmCase($lang, $report, 'manual.observe_open_app_button', tr($lang,
                    'Is the open_app button visible, and does tapping it open a MAX app or show a client error?',
                    'Видна ли open_app-кнопка, и открывает ли нажатие MAX app или показывает ошибку клиента?'
                ));
            } else {
                $report->skip('manual.observe_open_app_button', tr($lang,
                    'open_app button message was not sent',
                    'сообщение с open_app-кнопкой не было отправлено'
                ));
            }
        } else {
            $reason = tr($lang, 'tester did not provide open_app web_app', 'тестер не указал web_app для open_app');
            $report->skip('bot.send_message_to_chat(open_app_button)', $reason);
            $report->skip('manual.observe_open_app_button', $reason);
        }

        $clipboardBody = NewMessageBody::text(tr($lang,
            'MAX platform probe: clipboard button.',
            'Проверка платформы MAX: clipboard-кнопка.'
        ))->withKeyboard(new KeyboardPayload([
            [Button::clipboard(tr($lang, 'Copy text', 'Скопировать текст'), 'maxoxide-live-clipboard-payload')],
        ]));
        $clipboardMessage = $harness->apiCase($report, 'bot.send_message_to_chat(clipboard_button)',
            fn(Bot $b) => $b->sendMessageToChat($privateChatId, $clipboardBody));
        if ($clipboardMessage !== null) {
            confirmCase($lang, $report, 'manual.observe_clipboard_button', tr($lang,
                'Is the clipboard button visible, and does tapping it copy the expected text?',
                'Видна ли clipboard-кнопка, и копирует ли нажатие ожидаемый текст?'
            ));
        } else {
            $report->skip('manual.observe_clipboard_button', tr($lang,
                'clipboard button message was not sent',
                'сообщение с clipboard-кнопкой не было отправлено'
            ));
        }

        if (confirm($lang, tr($lang,
            'Probe ChatButton now? WARNING: tapping it creates a real chat and adds the bot as admin. Type `y` only if this is acceptable.',
            'Проверить ChatButton сейчас? ВНИМАНИЕ: нажатие создаёт настоящий чат и добавляет бота администратором. Введите `y`, только если это допустимо.'
        ), false)) {
            $startPayload = 'maxoxide-live-chat-' . time();
            $chatButtonBody = NewMessageBody::text(tr($lang,
                'MAX platform probe: chat button. Tapping it may create a real chat.',
                'Проверка платформы MAX: chat-кнопка. Нажатие может создать реальный чат.'
            ))->withKeyboard(new KeyboardPayload([
                [Button::chatFull(
                    tr($lang, 'Create chat', 'Создать чат'),
                    'maxoxide live chat',
                    'Created by maxoxide PHP live test',
                    $startPayload,
                    time()
                )],
            ]));
            $harness->pause();
            printCase('bot.send_message_to_chat(chat_button)');
            try {
                $harness->bot->sendMessageToChat($privateChatId, $chatButtonBody);
                $report->pass('bot.send_message_to_chat(chat_button)', tr($lang, 'ok', 'ok'));
                echo "   PASS\n";
                $harness->waitCase($report, 'manual.message_chat_created', tr($lang,
                    'Tap the chat button and wait for message_chat_created.',
                    'Нажмите chat-кнопку и дождитесь message_chat_created.'
                ), MANUAL_WAIT_SECS, fn(Update $u) => $u->type === 'message_chat_created'
                    && ($u->startPayload === null || $u->startPayload === $startPayload));
            } catch (MaxException $e) {
                $detail = $e->getMessage();
                if (strpos($detail, 'deserialize') !== false || strpos($detail, '400') !== false) {
                    $report->skip('bot.send_message_to_chat(chat_button)', tr($lang,
                        "MAX rejected documented ChatButton JSON; platform limitation: {$detail}",
                        "MAX отклонил документированный JSON ChatButton; ограничение платформы: {$detail}"
                    ));
                    echo "   SKIP: {$detail}\n";
                    echo tr($lang, 'Outgoing ChatButton JSON rejected by MAX:', 'Исходящий JSON ChatButton, который MAX отклонил:') . "\n";
                    echo json_encode($chatButtonBody->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
                    if (confirm($lang, tr($lang,
                        'If you have any existing chat button available, wait for message_chat_created now?',
                        'Если есть любая уже доступная chat-кнопка, подождать message_chat_created сейчас?'
                    ), false)) {
                        $harness->waitCase($report, 'manual.message_chat_created', tr($lang,
                            'Tap an existing chat button now.',
                            'Нажмите существующую chat-кнопку сейчас.'
                        ), MANUAL_WAIT_SECS, fn(Update $u) => $u->type === 'message_chat_created');
                    } else {
                        $report->skip('manual.message_chat_created', tr($lang, 'tester skipped message_chat_created wait', 'тестер пропустил ожидание message_chat_created'));
                    }
                } else {
                    $report->fail('bot.send_message_to_chat(chat_button)', $detail);
                    $report->skip('manual.message_chat_created', tr($lang, 'chat button message was not sent', 'сообщение с chat-кнопкой не было отправлено'));
                    echo "   FAIL: {$detail}\n";
                }
            }
        } else {
            $report->skip('bot.send_message_to_chat(chat_button)', tr($lang, 'tester skipped ChatButton probe', 'тестер пропустил проверку ChatButton'));
            $report->skip('manual.message_chat_created', tr($lang, 'tester skipped ChatButton probe', 'тестер пропустил проверку ChatButton'));
        }

        // Callback button test
        if (confirm($lang, tr($lang,
            'Test callback button now? Type `y` to wait for click, anything else to skip.',
            'Проверить callback-кнопку сейчас? Введите `y`, чтобы ждать нажатие, иначе шаг будет пропущен.'
        ), false)) {
            $callbackUpdate = $harness->waitCase($report, 'manual.callback_click',
                sprintf(tr($lang, 'Press `%s` in Max.', 'Нажмите `%s` в Max.'), $callbackBtnText),
                MANUAL_WAIT_SECS,
                fn(Update $u) => $u->type === 'message_callback' && $u->callback?->payload === 'live:callback'
            );
            if ($callbackUpdate !== null && $callbackUpdate->callback !== null) {
                $cbId = $callbackUpdate->callback->callbackId;
                $harness->apiCase($report, 'bot.answer_callback', function (Bot $b) use ($cbId, $lang) {
                    $ans = new AnswerCallbackBody($cbId);
                    $ans->notification = tr($lang, 'Callback acknowledged.', 'Callback подтверждён.');
                    return $b->answerCallback($ans);
                });
            }
        } else {
            $report->skip('manual.callback_click', tr($lang, 'tester skipped callback interaction', 'тестер пропустил взаимодействие с callback-кнопкой'));
            $report->skip('bot.answer_callback', tr($lang, 'callback interaction was skipped', 'взаимодействие с callback-кнопкой было пропущено'));
        }

        // Message button test
        if (confirm($lang, tr($lang,
            'Test message button now? Type `y` to wait for the generated message, anything else to skip.',
            'Проверить message-кнопку сейчас? Введите `y`, чтобы ждать сгенерированное сообщение, иначе шаг будет пропущен.'
        ), false)) {
            $harness->waitCase($report, 'manual.message_button',
                sprintf(tr($lang, 'Press `%s` in Max.', 'Нажмите `%s` в Max.'), $msgBtnText),
                MANUAL_WAIT_SECS,
                fn(Update $u) => $u->type === 'message_created'
                    && $u->message?->chatId() === $privateChatId
                    && $u->message?->text() === $msgBtnText
            );
        } else {
            $report->skip('manual.message_button', tr($lang, 'tester skipped message button interaction', 'тестер пропустил взаимодействие с message-кнопкой'));
        }

        // Contact button test
        if (confirm($lang, tr($lang,
            'Test request-contact button now? Type `y` to wait for shared contact, anything else to skip.',
            'Проверить кнопку запроса контакта сейчас? Введите `y`, чтобы ждать отправку контакта, иначе шаг будет пропущен.'
        ), false)) {
            $contactUpdate = $harness->waitCase($report, 'manual.contact_share',
                sprintf(tr($lang, 'Press `%s` in Max.', 'Нажмите `%s` в Max.'), $contactBtnText),
                MANUAL_WAIT_SECS,
                fn(Update $u) => $u->type === 'message_created'
                    && $u->message?->chatId() === $privateChatId
                    && messageHasAttachment($u, fn($a) => $a->type === 'contact')
            );
            if ($contactUpdate !== null) {
                $phone = extractContactPhone($contactUpdate);
                if ($phone !== null) {
                    $report->pass('manual.contact_phone_present', tr($lang, "phone={$phone}", "телефон={$phone}"));
                } else {
                    $report->fail('manual.contact_phone_present', tr($lang, 'contact attachment was received, but no phone was found in vcf_phone or vcf_info', 'contact-вложение пришло, но телефон не найден ни в vcf_phone, ни в vcf_info'));
                }
                $contact = firstContactAttachment($contactUpdate);
                if ($contact !== null && $contact->hash !== null) {
                    if ($contact->validateHash($cfg['token'])) {
                        $report->pass('manual.contact_hash_valid', tr($lang, 'contact hash is valid', 'hash контакта валиден'));
                    } else {
                        $report->fail('manual.contact_hash_valid', tr($lang, 'contact hash is present but invalid for the bot token', 'hash контакта есть, но невалиден для токена бота'));
                    }
                } else {
                    $report->skip('manual.contact_hash_valid', tr($lang, 'contact hash is missing', 'hash контакта отсутствует'));
                }
                if ($contact !== null && $contact->maxInfo !== null) {
                    $report->pass('manual.contact_max_info_present', "user_id={$contact->maxInfo->userId}");
                } else {
                    $report->skip('manual.contact_max_info_present', tr($lang, 'contact max_info is missing', 'max_info контакта отсутствует'));
                }
            } else {
                $report->skip('manual.contact_phone_present', tr($lang, 'contact share step did not complete', 'шаг отправки контакта не был завершён'));
                $report->skip('manual.contact_hash_valid', tr($lang, 'contact share step did not complete', 'шаг отправки контакта не был завершён'));
                $report->skip('manual.contact_max_info_present', tr($lang, 'contact share step did not complete', 'шаг отправки контакта не был завершён'));
            }
        } else {
            $report->skip('manual.contact_share', tr($lang, 'tester skipped contact share', 'тестер пропустил отправку контакта'));
            $report->skip('manual.contact_phone_present', tr($lang, 'tester skipped contact share', 'тестер пропустил отправку контакта'));
            $report->skip('manual.contact_hash_valid', tr($lang, 'tester skipped contact share', 'тестер пропустил отправку контакта'));
            $report->skip('manual.contact_max_info_present', tr($lang, 'tester skipped contact share', 'тестер пропустил отправку контакта'));
        }

        // Location button test
        if (confirm($lang, tr($lang,
            'Test request-location button now? Type `y` to wait for shared location or a client map-card fallback, anything else to skip.',
            'Проверить кнопку запроса геопозиции сейчас? Введите `y`, чтобы ждать геопозицию или fallback-карточку карты, иначе шаг будет пропущен.'
        ), false)) {
            $locationUpdate = $harness->waitCase($report, 'manual.location_share',
                sprintf(tr($lang, 'Press `%s` in Max.', 'Нажмите `%s` в Max.'), $locationBtnText),
                MANUAL_WAIT_SECS,
                fn(Update $u) => $u->type === 'message_created'
                    && $u->message?->chatId() === $privateChatId
                    && (
                        messageHasAttachment($u, fn($a) => $a->type === 'location')
                        || looksLikeClientMapCard($u)
                    )
            );

            if ($locationUpdate !== null && messageHasAttachment($locationUpdate, fn($a) => $a->type === 'location')) {
                $report->pass('manual.location_structured_payload', tr($lang,
                    'structured location attachment received',
                    'получено структурированное location-вложение'
                ));
            } elseif ($locationUpdate !== null && looksLikeClientMapCard($locationUpdate)) {
                $report->skip('manual.location_structured_payload', tr($lang,
                    'MAX client sent a map link/card instead of a structured location attachment',
                    'клиент MAX отправил ссылку/карточку карты вместо структурированного location-вложения'
                ));
            } else {
                $report->skip('manual.location_structured_payload', tr($lang,
                    'location share step did not complete',
                    'шаг отправки геопозиции не был завершён'
                ));
            }
        } else {
            $report->skip('manual.location_share', tr($lang, 'tester skipped location share', 'тестер пропустил отправку геопозиции'));
            $report->skip('manual.location_structured_payload', tr($lang, 'tester skipped location share', 'тестер пропустил отправку геопозиции'));
        }
    }

    // ── Manual client attachment ───────────────────────────────────────────────
    if (confirm($lang, tr($lang,
        'Test manual file/photo attachment from the Max client? Type `y` to wait for an incoming attachment.',
        'Проверить ручную отправку файла/фото из клиента Max? Введите `y`, чтобы ждать входящее вложение.'
    ), false)) {
        $harness->waitCase($report, 'manual.client_attachment',
            tr($lang, 'Attach any file or image to the private chat in Max.', 'Прикрепите любой файл или изображение в личный чат в Max.'),
            MANUAL_WAIT_SECS,
            fn(Update $u) => $u->type === 'message_created'
                && $u->message?->chatId() === $privateChatId
                && messageHasAttachment($u, fn($a) => $a->type !== 'inline_keyboard')
        );
    } else {
        $report->skip('manual.client_attachment', tr($lang, 'tester skipped client-side attachment check', 'тестер пропустил проверку вложения со стороны клиента'));
    }

    // ── /get_my_id command ────────────────────────────────────────────────────
    if (confirm($lang, tr($lang,
        'Test `/get_my_id` now? Type `y`, then send `/get_my_id` to the bot.',
        'Проверить `/get_my_id` сейчас? Введите `y`, затем отправьте `/get_my_id` боту.'
    ), false)) {
        $idUpdate = $harness->waitCase($report, 'manual.get_my_id_command',
            tr($lang, 'Send `/get_my_id` in the private chat.', 'Отправьте `/get_my_id` в личный чат.'),
            MANUAL_WAIT_SECS,
            fn(Update $u) => $u->type === 'message_created'
                && $u->message?->chatId() === $privateChatId
                && $u->message?->text() === '/get_my_id'
        );
        if ($idUpdate !== null) {
            $uid = extractSenderUserId($idUpdate);
            if ($uid !== null) {
                $privateUserId = $uid;
                $report->pass('manual.get_my_id_user_id', "user_id={$uid}");
                $replyText = tr($lang, "Your Max ID: {$uid}", "Ваш Max ID: {$uid}");
                $harness->apiCase($report, 'bot.send_text_to_chat(get_my_id_response)',
                    fn(Bot $b) => $b->sendTextToChat($privateChatId, $replyText));
            } else {
                $report->fail('manual.get_my_id_user_id', tr($lang, 'message was received, but sender.user_id is missing', 'сообщение получено, но sender.user_id отсутствует'));
                $report->skip('bot.send_text_to_chat(get_my_id_response)', tr($lang, 'sender.user_id is missing', 'sender.user_id отсутствует'));
            }
        } else {
            $report->skip('manual.get_my_id_user_id', tr($lang, '`/get_my_id` step did not complete', 'шаг `/get_my_id` не был завершён'));
            $report->skip('bot.send_text_to_chat(get_my_id_response)', tr($lang, '`/get_my_id` step did not complete', 'шаг `/get_my_id` не был завершён'));
        }
    } else {
        $report->skip('manual.get_my_id_command', tr($lang, 'tester skipped `/get_my_id`', 'тестер пропустил `/get_my_id`'));
        $report->skip('manual.get_my_id_user_id', tr($lang, 'tester skipped `/get_my_id`', 'тестер пропустил `/get_my_id`'));
        $report->skip('bot.send_text_to_chat(get_my_id_response)', tr($lang, 'tester skipped `/get_my_id`', 'тестер пропустил `/get_my_id`'));
    }

    // ── Edited message event ──────────────────────────────────────────────────
    if (confirm($lang, tr($lang,
        'Test edited-message update? Type `y`, then edit your last text message in Max.',
        'Проверить событие редактирования сообщения? Введите `y`, затем отредактируйте последнее текстовое сообщение в Max.'
    ), false)) {
        $harness->waitCase($report, 'manual.message_edit',
            tr($lang, 'Edit a message in the private chat in Max.', 'Отредактируйте сообщение в личном чате в Max.'),
            MANUAL_WAIT_SECS,
            fn(Update $u) => $u->type === 'message_edited' && $u->message?->chatId() === $privateChatId
        );
    } else {
        $report->skip('manual.message_edit', tr($lang, 'tester skipped edited-message check', 'тестер пропустил проверку редактирования сообщения'));
    }

    // ── Edit / get / delete plain message ─────────────────────────────────────
    if ($plainMessage !== null) {
        $mid = $plainMessage->messageId();
        $harness->apiCase($report, 'bot.edit_message',
            fn(Bot $b) => $b->editMessage($mid, NewMessageBody::text('maxoxide live test: edited text message')));
        $harness->apiCase($report, 'bot.get_message', fn(Bot $b) => $b->getMessage($mid));
        $harness->apiCase($report, 'bot.get_messages', fn(Bot $b) => $b->getMessages($privateChatId, 20));
        $harness->apiCase($report, 'bot.get_messages_by_ids', fn(Bot $b) => $b->getMessagesByIds([$mid], 1));
        $harness->apiCase($report, 'bot.delete_message', fn(Bot $b) => $b->deleteMessage($mid));
    } else {
        $report->skipMany(['bot.edit_message', 'bot.get_message', 'bot.get_messages', 'bot.get_messages_by_ids', 'bot.delete_message'],
            tr($lang, 'plain text message was not sent successfully', 'простое текстовое сообщение не было успешно отправлено'));
    }

    return ['chat_id' => $privateChatId, 'user_id' => $privateUserId];
}

// ─────────────────────────────────────────────────────────────────────────────

function runUploadPhase(Harness $harness, Report $report, array $cfg, ?int $privateChatId, ?int $privateUserId): void
{
    $lang = $cfg['lang'];
    printSection(tr($lang, 'Uploads', 'Загрузки'));

    foreach ([UploadType::IMAGE, UploadType::VIDEO, UploadType::AUDIO, UploadType::FILE] as $type) {
        $harness->apiCase($report, "bot.get_upload_url({$type})", fn(Bot $b) => $b->getUploadUrl($type));
    }

    $uploadPath  = prepareUploadFile($cfg['upload_path']);
    echo tr($lang, 'Upload source file', 'Файл-источник для загрузки') . ": {$uploadPath}\n";

    $uploadFileToken = $harness->apiCase($report, 'bot.upload_file',
        fn(Bot $b) => $b->uploadFile(UploadType::FILE, $uploadPath, 'maxoxide-live-upload.txt', 'text/plain'));

    $uploadBytesToken = $harness->apiCase($report, 'bot.upload_bytes',
        fn(Bot $b) => $b->uploadBytes(UploadType::FILE, "maxoxide live upload_bytes payload\n", 'maxoxide-live-bytes.txt', 'text/plain'));

    if ($privateChatId !== null) {
        if ($uploadFileToken !== null) {
            $body = NewMessageBody::text('File attachment sent via upload_file.')
                ->withAttachment(NewAttachment::file($uploadFileToken));
            $harness->apiCase($report, 'bot.send_message_to_chat(upload_file_attachment)',
                fn(Bot $b) => $b->sendMessageToChat($privateChatId, $body));
        } else {
            $report->skip('bot.send_message_to_chat(upload_file_attachment)', tr($lang, 'upload_file did not return a token', 'upload_file не вернул токен'));
        }

        $filename = filenameFromPath($uploadPath, 'maxoxide-live-upload.txt');
        $mime = mimeForPath($uploadPath, 'application/octet-stream');
        $harness->apiCase($report, 'bot.send_file_to_chat',
            fn(Bot $b) => $b->sendFileToChat($privateChatId, $uploadPath, $filename, $mime, 'File sent via send_file_to_chat.'));

        $harness->apiCase($report, 'bot.send_file_bytes_to_chat',
            fn(Bot $b) => $b->sendFileBytesToChat(
                $privateChatId,
                "maxoxide live send_file_bytes_to_chat payload\n",
                'maxoxide-live-bytes-helper.txt',
                'text/plain',
                'File sent via send_file_bytes_to_chat.'
            ));
    } else {
        $report->skipMany([
            'bot.send_message_to_chat(upload_file_attachment)',
            'bot.send_file_to_chat',
            'bot.send_file_bytes_to_chat',
        ], tr($lang, 'private chat is not available', 'личный чат недоступен'));
    }

    if ($privateUserId !== null) {
        if ($uploadBytesToken !== null) {
            $body = NewMessageBody::text('File attachment sent via upload_bytes to user_id.')
                ->withAttachment(NewAttachment::file($uploadBytesToken));
            $harness->apiCase($report, 'bot.send_message_to_user(upload_bytes_attachment)',
                fn(Bot $b) => $b->sendMessageToUser($privateUserId, $body));
        } else {
            $report->skip('bot.send_message_to_user(upload_bytes_attachment)', tr($lang, 'upload_bytes did not return a token', 'upload_bytes не вернул токен'));
        }

        $filename = filenameFromPath($uploadPath, 'maxoxide-live-upload.txt');
        $mime = mimeForPath($uploadPath, 'application/octet-stream');
        $harness->apiCase($report, 'bot.send_file_to_user',
            fn(Bot $b) => $b->sendFileToUser($privateUserId, $uploadPath, $filename, $mime, 'File sent via send_file_to_user.'));

        $harness->apiCase($report, 'bot.send_file_bytes_to_user',
            fn(Bot $b) => $b->sendFileBytesToUser(
                $privateUserId,
                "maxoxide live send_file_bytes_to_user payload\n",
                'maxoxide-live-bytes-helper.txt',
                'text/plain',
                'File sent via send_file_bytes_to_user.'
            ));
    } else {
        $report->skipMany([
            'bot.send_message_to_user(upload_bytes_attachment)',
            'bot.send_file_to_user',
            'bot.send_file_bytes_to_user',
        ], tr($lang, 'private user_id is not available', 'private user_id недоступен'));
    }

    if ($privateChatId !== null) {
        if ($cfg['upload_image_path'] !== null) {
            $imagePath = $cfg['upload_image_path'];
            $harness->apiCase($report, 'bot.send_image_to_chat',
                fn(Bot $b) => $b->sendImageToChat(
                    $privateChatId,
                    $imagePath,
                    filenameFromPath($imagePath, 'maxoxide-live-image'),
                    mimeForPath($imagePath, 'image/jpeg'),
                    'Image sent via send_image_to_chat.'
                ));
        } else {
            $report->skip('bot.send_image_to_chat', tr($lang, 'image path was not provided', 'путь к изображению не был указан'));
        }

        if ($cfg['upload_video_path'] !== null) {
            $videoPath = $cfg['upload_video_path'];
            $videoMessage = $harness->apiCase($report, 'bot.send_video_to_chat',
                fn(Bot $b) => $b->sendVideoToChat(
                    $privateChatId,
                    $videoPath,
                    filenameFromPath($videoPath, 'maxoxide-live-video'),
                    mimeForPath($videoPath, 'video/mp4'),
                    'Video sent via send_video_to_chat.'
                ));
            if ($videoMessage !== null) {
                $videoToken = extractVideoToken($videoMessage);
                if ($videoToken !== null) {
                    $harness->apiCase($report, 'bot.get_video(uploaded_video)', fn(Bot $b) => $b->getVideo($videoToken));
                } else {
                    $report->fail('bot.get_video(uploaded_video)', tr($lang,
                        'sent video message did not contain a video token',
                        'отправленное видео-сообщение не содержит video token'
                    ));
                }
            } else {
                $report->skip('bot.get_video(uploaded_video)', tr($lang,
                    'send_video_to_chat did not succeed',
                    'send_video_to_chat не завершился успешно'
                ));
            }
        } else {
            $report->skip('bot.send_video_to_chat', tr($lang, 'video path was not provided', 'путь к видео не был указан'));
            $report->skip('bot.get_video(uploaded_video)', tr($lang, 'video path was not provided', 'путь к видео не был указан'));
        }

        if ($cfg['upload_audio_path'] !== null) {
            $audioPath = $cfg['upload_audio_path'];
            $harness->apiCase($report, 'bot.send_audio_to_chat',
                fn(Bot $b) => $b->sendAudioToChat(
                    $privateChatId,
                    $audioPath,
                    filenameFromPath($audioPath, 'maxoxide-live-audio'),
                    mimeForPath($audioPath, 'audio/mpeg'),
                    'Audio sent via send_audio_to_chat.'
                ));
        } else {
            $report->skip('bot.send_audio_to_chat', tr($lang, 'audio path was not provided', 'путь к аудиофайлу не был указан'));
        }
    } else {
        $report->skipMany([
            'bot.send_image_to_chat',
            'bot.send_video_to_chat',
            'bot.get_video(uploaded_video)',
            'bot.send_audio_to_chat',
        ], tr($lang, 'private chat is not available', 'личный чат недоступен'));
    }
}

// ─────────────────────────────────────────────────────────────────────────────

function runWebhookPhase(Harness $harness, Report $report, array $cfg): void
{
    $lang = $cfg['lang'];
    printSection(tr($lang, 'Webhook', 'Webhook'));

    $harness->apiCase($report, 'bot.get_subscriptions', fn(Bot $b) => $b->getSubscriptions());

    if ($cfg['webhook_url'] === null) {
        $report->skipMany(['bot.subscribe', 'bot.unsubscribe'],
            tr($lang, 'webhook URL was not provided', 'webhook URL не был указан'));
        return;
    }

    $url    = $cfg['webhook_url'];
    $secret = $cfg['webhook_secret'];

    $harness->apiCase($report, 'bot.subscribe', function (Bot $b) use ($url, $secret) {
        $body = new SubscribeBody($url);
        $body->secret = $secret;
        return $b->subscribe($body);
    });

    $harness->apiCase($report, 'bot.unsubscribe', fn(Bot $b) => $b->unsubscribe($url));
}

// ─────────────────────────────────────────────────────────────────────────────

function runCommandsPhase(Harness $harness, Report $report, string $lang): void
{
    printSection(tr($lang, 'Commands', 'Команды'));

    if (!confirm($lang, tr($lang,
        'Probe experimental bot.set_my_commands? The public MAX REST API does not currently document a write endpoint and may return 404. This also changes the bot command menu and is not restored automatically. Type `y` to proceed.',
        'Проверить экспериментальный bot.set_my_commands? Публичный REST API MAX сейчас не документирует write-эндпоинт и может вернуть 404. Также это изменит меню команд бота и автоматически не откатывается. Введите `y`, чтобы продолжить.'
    ), false)) {
        $report->skip('bot.set_my_commands', tr($lang, 'tester did not confirm probing the experimental command-menu endpoint', 'тестер не подтвердил проверку экспериментального эндпоинта меню команд'));
        return;
    }

    $harness->pause();
    printCase('bot.set_my_commands');
    try {
        $commands = [
            new BotCommand('live', 'Run the live API test'),
            new BotCommand('group_live', 'Trigger the group phase'),
        ];
        $harness->bot->setMyCommands($commands);
        $report->pass('bot.set_my_commands', tr($lang, 'ok', 'ok'));
        echo "   PASS\n";
    } catch (MaxException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, '/me/commands') !== false && strpos($msg, '404') !== false) {
            $detail = tr($lang,
                'public MAX API does not currently expose POST /me/commands; treating this as a platform gap',
                'публичный MAX API сейчас не предоставляет POST /me/commands; шаг помечен как платформенное ограничение'
            );
            $report->skip('bot.set_my_commands', $detail);
            echo "   SKIP: {$detail}\n";
        } else {
            $report->fail('bot.set_my_commands', $msg);
            echo "   FAIL: {$msg}\n";
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────

function runGroupPhase(Harness $harness, Report $report, array $cfg, array $knownChats, ?int $knownUserId): void
{
    $lang = $cfg['lang'];

    if (!confirm($lang, tr($lang,
        'Run the optional group-chat phase now? Type `y` to continue, anything else to skip.',
        'Запустить необязательный этап с групповым чатом сейчас? Введите `y`, чтобы продолжить, иначе этап будет пропущен.'
    ), false)) {
        $report->skipMany([
            'manual.group_activation', 'bot.get_chat(group)', 'bot.get_members',
            'bot.get_members_by_ids', 'bot.get_admins', 'bot.get_my_membership',
            'bot.send_sender_action(typing_on)', 'bot.send_sending_image',
            'bot.send_sending_video', 'bot.send_sending_audio', 'bot.send_sending_file',
            'bot.mark_seen',
            'manual.observe_typing_indicator', 'bot.send_message_to_chat(group)',
            'bot.pin_message', 'bot.get_pinned_message', 'bot.unpin_message',
            'bot.edit_chat', 'bot.edit_chat(rollback)', 'bot.add_admins', 'bot.remove_admin', 'bot.add_members',
            'bot.remove_member', 'bot.remove_member_with_options(block)', 'bot.delete_chat', 'bot.leave_chat',
        ], tr($lang, 'tester skipped the optional group-chat phase', 'тестер пропустил необязательный этап с групповым чатом'));
        return;
    }

    printSection(tr($lang, 'Group Chat', 'Групповой чат'));
    echo tr($lang, '1. Add the bot to a disposable group chat where it has admin rights.', '1. Добавьте бота во временную группу, где у него есть права администратора.') . "\n";
    if ($cfg['bot_link'] !== null) {
        echo '   ' . tr($lang, 'Bot URL', 'URL бота') . ': ' . $cfg['bot_link'] . "\n";
    }
    echo tr($lang, '2. Send `/group_live` in that group.', '2. Отправьте `/group_live` в этой группе.') . "\n";
    if ($knownUserId !== null) {
        echo tr($lang, "Known user_id from the private phase: {$knownUserId}", "Известный user_id из личного этапа: {$knownUserId}") . "\n";
    }

    $activated = $harness->waitCase($report, 'manual.group_activation',
        tr($lang, 'Waiting for `/group_live` in a group or channel.', 'Ожидание `/group_live` в группе или канале.'),
        GROUP_WAIT_SECS,
        fn(Update $u) => $u->type === 'message_created'
            && $u->message !== null
            && $u->message->recipient->chatType !== 'dialog'
            && $u->message->text() === '/group_live'
    );

    $groupChatId = $activated?->message?->chatId();

    if ($groupChatId === null) {
        if (!empty($knownChats)) {
            echo tr($lang, 'Known group chats from bot.get_chats:', 'Известные групповые чаты из bot.get_chats:') . "\n";
            printKnownChats($knownChats, $lang);
        }
        $groupChatId = promptOptionalInt($lang, tr($lang,
            'Enter a group chat_id manually to continue the group phase, or leave blank to skip',
            'Введите group chat_id вручную, чтобы продолжить групповой этап, или оставьте поле пустым для пропуска'
        ));
    }

    if ($groupChatId === null) {
        $report->skipMany([
            'bot.get_chat(group)', 'bot.get_members', 'bot.get_members_by_ids', 'bot.get_admins', 'bot.get_my_membership',
            'bot.send_sender_action(typing_on)', 'bot.send_sending_image', 'bot.send_sending_video',
            'bot.send_sending_audio', 'bot.send_sending_file', 'bot.mark_seen',
            'bot.pin_message', 'bot.get_pinned_message', 'bot.unpin_message',
            'bot.edit_chat', 'bot.add_admins', 'bot.remove_admin', 'bot.add_members', 'bot.remove_member',
            'bot.remove_member_with_options(block)',
            'bot.delete_chat', 'bot.leave_chat',
        ], tr($lang, 'group chat was not selected', 'групповой чат не был выбран'));
        return;
    }

    echo tr($lang, 'Selected group chat id', 'Выбранный group chat id') . ": {$groupChatId}\n";

    $groupChat = $harness->apiCase($report, 'bot.get_chat(group)', fn(Bot $b) => $b->getChat($groupChatId));

    $members = $harness->apiCase($report, 'bot.get_members', fn(Bot $b) => $b->getMembers($groupChatId, 100));
    if ($members !== null) {
        printChatMembers($members->members, $lang);
    }

    $selectedMemberId = $knownUserId;
    if ($selectedMemberId === null && $members !== null && !empty($members->members)) {
        $selectedMemberId = $members->members[0]->userId;
    }
    if ($selectedMemberId !== null) {
        $harness->apiCase($report, 'bot.get_members_by_ids',
            fn(Bot $b) => $b->getMembersByIds($groupChatId, [$selectedMemberId]));
    } else {
        $report->skip('bot.get_members_by_ids', tr($lang, 'no member user_id is available', 'нет доступного user_id участника'));
    }

    $harness->apiCase($report, 'bot.get_admins', fn(Bot $b) => $b->getAdmins($groupChatId));
    $botMembership = $harness->apiCase($report, 'bot.get_my_membership', fn(Bot $b) => $b->getMyMembership($groupChatId));
    if ($botMembership !== null) {
        printBotMembership($botMembership, $lang);
    }

    $actionOk = $harness->apiCase($report, 'bot.send_sender_action(typing_on)',
        fn(Bot $b) => $b->sendSenderAction($groupChatId, SenderAction::TYPING_ON));
    if ($actionOk !== null) {
        if (confirm($lang, tr($lang, 'Did the typing indicator become visible in the group chat?', 'Появился ли в групповом чате индикатор набора текста?'), true)) {
            $report->pass('manual.observe_typing_indicator', tr($lang, 'tester confirmed', 'тестер подтвердил'));
        } else {
            $report->skip('manual.observe_typing_indicator', tr($lang,
                'tester did not observe a visible typing indicator in this client/run',
                'тестер не увидел индикатор набора текста в этом клиенте/прогоне'
            ));
        }
    }

    $harness->apiCase($report, 'bot.send_sending_image', fn(Bot $b) => $b->sendSendingImage($groupChatId));
    $harness->apiCase($report, 'bot.send_sending_video', fn(Bot $b) => $b->sendSendingVideo($groupChatId));
    $harness->apiCase($report, 'bot.send_sending_audio', fn(Bot $b) => $b->sendSendingAudio($groupChatId));
    $harness->apiCase($report, 'bot.send_sending_file', fn(Bot $b) => $b->sendSendingFile($groupChatId));
    $harness->apiCase($report, 'bot.mark_seen', fn(Bot $b) => $b->markSeen($groupChatId));

    $groupMessage = $harness->apiCase($report, 'bot.send_message_to_chat(group)',
        fn(Bot $b) => $b->sendMessageToChat($groupChatId, NewMessageBody::text('maxoxide live test: group message for pin/edit flow')));

    if ($groupMessage !== null) {
        $mid = $groupMessage->messageId();
        $pinBody = new PinMessageBody($mid, false);
        $harness->apiCase($report, 'bot.pin_message', fn(Bot $b) => $b->pinMessage($groupChatId, $pinBody));
        $harness->apiCase($report, 'bot.get_pinned_message', fn(Bot $b) => $b->getPinnedMessage($groupChatId));
        $harness->apiCase($report, 'bot.unpin_message', fn(Bot $b) => $b->unpinMessage($groupChatId));
    } else {
        $report->skipMany(['bot.pin_message', 'bot.get_pinned_message', 'bot.unpin_message'],
            tr($lang, 'group message setup failed', 'не удалось подготовить групповое сообщение'));
    }

    // edit_chat with automatic rollback
    if (confirm($lang, tr($lang,
        'Test bot.edit_chat with temporary title change and automatic rollback? Type `y` to proceed.',
        'Проверить bot.edit_chat с временной сменой title и автоматическим откатом? Введите `y`, чтобы продолжить.'
    ), false)) {
        if ($groupChat !== null && $groupChat->title !== null) {
            $originalTitle = $groupChat->title;
            $tempTitle     = "{$originalTitle} [live]";
            $editBody      = new EditChatBody();
            $editBody->title = $tempTitle;
            $harness->apiCase($report, 'bot.edit_chat', fn(Bot $b) => $b->editChat($groupChatId, $editBody));
            $rollbackBody  = new EditChatBody();
            $rollbackBody->title = $originalTitle;
            $harness->apiCase($report, 'bot.edit_chat(rollback)', fn(Bot $b) => $b->editChat($groupChatId, $rollbackBody));
        } else {
            $reason = tr($lang, 'group chat title is empty, rollback would be unsafe', 'title группового чата пустой, откат был бы небезопасен');
            $report->skip('bot.edit_chat', $reason);
            $report->skip('bot.edit_chat(rollback)', $reason);
        }
    } else {
        $reason = tr($lang, 'tester skipped visible group mutation', 'тестер пропустил видимое изменение группы');
        $report->skip('bot.edit_chat', $reason);
        $report->skip('bot.edit_chat(rollback)', $reason);
    }

    // add/remove admin
    $adminUserId = promptOptionalInt($lang, tr($lang,
        'Optional platform probe: enter a user_id for bot.add_admins/bot.remove_admin, or leave blank to skip',
        'Опциональная platform-проверка: введите user_id для bot.add_admins/bot.remove_admin или оставьте поле пустым'
    ));
    if ($adminUserId !== null) {
        if (typedConfirmation(
            tr($lang,
                'Type `ADMIN` to confirm temporary admin rights change for this user_id',
                'Введите `ADMIN`, чтобы подтвердить временное изменение admin-прав для этого user_id'
            ),
            'ADMIN'
        )) {
            if (!memberCanAddAdmins($botMembership)) {
                $report->skipMany(['bot.add_admins', 'bot.remove_admin'], tr($lang,
                    'bot membership does not include the add_admins permission',
                    'в правах бота нет add_admins'
                ));
            } else {
                $permissions = adminProbePermissions($botMembership);
                $added = $harness->apiCase($report, 'bot.add_admins',
                    fn(Bot $b) => $b->addAdmins($groupChatId, [new ChatAdmin($adminUserId, $permissions)]));
                if ($added !== null) {
                    $harness->apiCase($report, 'bot.remove_admin',
                        fn(Bot $b) => $b->removeAdmin($groupChatId, $adminUserId));
                } else {
                    $report->skip('bot.remove_admin', tr($lang,
                        'bot.add_admins did not succeed',
                        'bot.add_admins не завершился успешно'
                    ));
                }
            }
        } else {
            $report->skipMany(['bot.add_admins', 'bot.remove_admin'], tr($lang,
                'tester did not confirm admin rights probe',
                'тестер не подтвердил проверку admin-прав'
            ));
        }
    } else {
        $report->skipMany(['bot.add_admins', 'bot.remove_admin'], tr($lang,
            'tester did not provide a user_id',
            'тестер не указал user_id'
        ));
    }

    // add/remove member
    $memberUserId = promptOptionalInt($lang, tr($lang,
        'Enter a user_id for bot.add_members/bot.remove_member, or leave blank to skip',
        'Введите user_id для bot.add_members/bot.remove_member, или оставьте поле пустым для пропуска'
    ));
    if ($memberUserId !== null) {
        $added = $harness->apiCase($report, 'bot.add_members',
            fn(Bot $b) => $b->addMembers($groupChatId, [$memberUserId]));
        if ($added !== null) {
            $harness->apiCase($report, 'bot.remove_member',
                fn(Bot $b) => $b->removeMember($groupChatId, $memberUserId));
        } else {
            $report->skip('bot.remove_member', tr($lang, 'bot.add_members did not succeed', 'bot.add_members не завершился успешно'));
        }
    } else {
        $reason = tr($lang, 'tester did not provide a user_id', 'тестер не указал user_id');
        $report->skip('bot.add_members', $reason);
        $report->skip('bot.remove_member', $reason);
    }

    $blockUserId = promptOptionalInt($lang, tr($lang,
        'Optional destructive probe: enter a user_id for bot.remove_member_with_options(block=true), or leave blank to skip',
        'Опциональная destructive-проверка: введите user_id для bot.remove_member_with_options(block=true), или оставьте поле пустым для пропуска'
    ));
    if ($blockUserId !== null) {
        if (typedConfirmation(
            tr($lang,
                'Type `BLOCK` to confirm removing this user with block=true',
                'Введите `BLOCK`, чтобы подтвердить удаление этого пользователя с block=true'
            ),
            'BLOCK'
        )) {
            $harness->apiCase($report, 'bot.remove_member_with_options(block)',
                fn(Bot $b) => $b->removeMemberWithOptions($groupChatId, $blockUserId, RemoveMemberOptions::block(true)));
        } else {
            $report->skip('bot.remove_member_with_options(block)', tr($lang,
                'tester did not confirm block=true removal',
                'тестер не подтвердил удаление с block=true'
            ));
        }
    } else {
        $report->skip('bot.remove_member_with_options(block)', tr($lang,
            'tester did not provide a user_id',
            'тестер не указал user_id'
        ));
    }

    // delete_chat (disposable)
    $deleteChatId = promptOptionalInt($lang, tr($lang,
        'Enter a disposable chat_id for bot.delete_chat, or leave blank to skip',
        'Введите disposable chat_id для bot.delete_chat, или оставьте поле пустым для пропуска'
    ));
    $deletedSelectedGroup = false;
    if ($deleteChatId !== null) {
        if (typedConfirmation(
            tr($lang, 'Type `DELETE` to confirm bot.delete_chat on the provided chat_id', 'Введите `УДАЛИТЬ`, чтобы подтвердить bot.delete_chat для указанного chat_id'),
            tr($lang, 'DELETE', 'УДАЛИТЬ')
        )) {
            $deleted = $harness->apiCase($report, 'bot.delete_chat', fn(Bot $b) => $b->deleteChat($deleteChatId));
            $deletedSelectedGroup = ($deleteChatId === $groupChatId && $deleted !== null);
        } else {
            $report->skip('bot.delete_chat', tr($lang, 'tester did not confirm delete_chat', 'тестер не подтвердил delete_chat'));
        }
    } else {
        $report->skip('bot.delete_chat', tr($lang, 'tester did not provide a disposable chat_id', 'тестер не указал disposable chat_id'));
    }

    // leave_chat
    if ($deletedSelectedGroup) {
        $report->skip('bot.leave_chat', tr($lang, 'selected group chat was deleted', 'выбранный групповой чат был удалён'));
    } elseif (confirm($lang, tr($lang,
        'Test bot.leave_chat on the selected group now? Type `y` to make the bot leave the group.',
        'Проверить bot.leave_chat для выбранной группы сейчас? Введите `y`, чтобы бот покинул группу.'
    ), false)) {
        $harness->apiCase($report, 'bot.leave_chat', fn(Bot $b) => $b->leaveChat($groupChatId));
    } else {
        $report->skip('bot.leave_chat', tr($lang, 'tester skipped leave_chat', 'тестер пропустил leave_chat'));
    }
}

function runOptionalDialogEventsPhase(Harness $harness, Report $report, array $cfg, int $privateChatId): void
{
    $lang = $cfg['lang'];
    printSection(tr($lang, 'Optional Dialog Events', 'Опциональные события диалога'));
    if (!confirm($lang, tr($lang,
        'Probe optional dialog events now? Run this at the end because some actions stop or remove the bot dialog.',
        'Проверить опциональные события диалога сейчас? Этот этап запускается в конце, потому что некоторые действия останавливают или удаляют диалог с ботом.'
    ), false)) {
        $report->skipMany([
            'manual.bot_stopped',
            'manual.dialog_cleared',
            'manual.dialog_muted',
            'manual.dialog_unmuted',
            'manual.dialog_removed',
        ], tr($lang, 'tester skipped optional dialog events', 'тестер пропустил опциональные события диалога'));
        return;
    }

    $events = [
        'manual.bot_stopped' => [
            'type' => 'bot_stopped',
            'instruction' => tr($lang, 'Stop the bot in the private dialog.', 'Остановите бота в личном диалоге.'),
        ],
        'manual.dialog_cleared' => [
            'type' => 'dialog_cleared',
            'instruction' => tr($lang, 'Clear the private dialog history with the bot.', 'Очистите историю личного диалога с ботом.'),
        ],
        'manual.dialog_muted' => [
            'type' => 'dialog_muted',
            'instruction' => tr($lang, 'Mute notifications in the private dialog with the bot.', 'Отключите уведомления в личном диалоге с ботом.'),
        ],
        'manual.dialog_unmuted' => [
            'type' => 'dialog_unmuted',
            'instruction' => tr($lang, 'Unmute notifications in the private dialog with the bot.', 'Включите уведомления в личном диалоге с ботом.'),
        ],
        'manual.dialog_removed' => [
            'type' => 'dialog_removed',
            'instruction' => tr($lang, 'Remove/delete the private dialog with the bot.', 'Удалите личный диалог с ботом.'),
        ],
    ];

    foreach ($events as $name => $event) {
        if (confirm($lang, tr($lang,
            "Wait for {$event['type']} now?",
            "Ждать {$event['type']} сейчас?"
        ), false)) {
            $harness->waitCase($report, $name, $event['instruction'], MANUAL_WAIT_SECS,
                fn(Update $u) => $u->type === $event['type'] && ($u->chatId === null || $u->chatId === $privateChatId));
        } else {
            $report->skip($name, tr($lang, 'tester skipped this optional event', 'тестер пропустил это опциональное событие'));
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Entry point
// ─────────────────────────────────────────────────────────────────────────────

// Language selection
while (true) {
    $langInput = prompt('Select language / Выберите язык [en/ru] [en]');
    $langInput = strtolower(trim($langInput));
    if ($langInput === '' || in_array($langInput, ['en', 'eng', 'english'], true)) {
        $lang = 'en';
        break;
    }
    if (in_array($langInput, ['ru', 'rus', 'russian', 'рус', 'русский'], true)) {
        $lang = 'ru';
        break;
    }
    echo "Expected `en` or `ru` / Ожидается `en` или `ru`.\n";
}

$cfg = promptConfig($lang);
$bot = new Bot($cfg['token'], max(1, $cfg['http_timeout']));
$report = new Report();
$webhookReceiver = null;
if ($cfg['transport'] === TRANSPORT_WEBHOOK) {
    try {
        $webhookReceiver = new LocalWebhookReceiver($cfg['webhook_listen_addr'] ?? '0.0.0.0:8080', $cfg['webhook_secret']);
        echo tr($lang, 'Local webhook receiver listening on', 'Локальный webhook receiver слушает')
            . ' ' . $webhookReceiver->localAddress() . "\n";
    } catch (Throwable $e) {
        $report->fail('manual.webhook_receiver_ready', $e->getMessage());
        $report->printSummary($lang);
        exit(1);
    }
}
$harness = new Harness($bot, $cfg['request_delay'], max(1, $cfg['poll_timeout']), $lang, $cfg['transport'], $webhookReceiver);

printSection(tr($lang, 'Live Test', 'Живой тест'));
echo tr($lang,
        "Interactive real-API run with transport {$cfg['transport']}, request delay {$cfg['request_delay']} ms, HTTP timeout {$cfg['http_timeout']} s, polling timeout {$cfg['poll_timeout']} s.",
        "Интерактивный прогон по реальному API: transport {$cfg['transport']}, задержка между запросами {$cfg['request_delay']} мс, HTTP timeout {$cfg['http_timeout']} c, polling timeout {$cfg['poll_timeout']} c."
    ) . "\n";

// get_me
$me = $harness->apiCase($report, 'bot.get_me', fn(Bot $b) => $b->getMe());
if ($me === null) {
    $report->printSummary($lang);
    exit(1);
}
echo tr($lang, 'Authenticated as', 'Аутентификация выполнена как') . ' @' . ($me->username ?? tr($lang, 'unknown', 'неизвестно')) . "\n";

// get_chats
$knownChats = [];
$chatList = $harness->apiCase($report, 'bot.get_chats', fn(Bot $b) => $b->getChats(100));
if ($chatList !== null) {
    printKnownChats($chatList->chats, $lang);
    $knownChats = $chatList->chats;
}

$disabledWebhookSubscriptions = [];
if ($cfg['transport'] === TRANSPORT_LONG_POLLING) {
    $prepared = prepareLongPollingPhase($harness, $report, $cfg);
    if ($prepared === null) {
        $report->printSummary($lang);
        exit(1);
    }
    $disabledWebhookSubscriptions = $prepared;
} elseif (!prepareWebhookPhase($harness, $report, $cfg)) {
    $report->printSummary($lang);
    exit(1);
}

// Flush backlog
if ($cfg['transport'] === TRANSPORT_LONG_POLLING) {
    try {
        $drained = $harness->flushUpdates();
        $report->pass('bot.get_updates', tr($lang, "marker synchronized, drained {$drained} backlog update(s)", "marker синхронизирован, очищено {$drained} backlog-обновлений"));
    } catch (\Throwable $e) {
        $report->fail('bot.get_updates', $e->getMessage());
        restoreDisabledWebhooks($harness, $report, $cfg, $disabledWebhookSubscriptions);
        $report->printSummary($lang);
        exit(1);
    }

    $rawResponse = $harness->apiCase($report, 'bot.get_updates_raw',
        fn(Bot $b) => $b->getUpdatesRaw($harness->marker(), 1, 1));
    if ($rawResponse !== null && $rawResponse->marker !== null) {
        $harness->setMarker($rawResponse->marker);
    }
    $typedFiltered = $harness->apiCase($report, 'bot.get_updates_with_types(message_created)',
        fn(Bot $b) => $b->getUpdatesWithTypes($harness->marker(), 1, 1, ['message_created']));
    if ($typedFiltered !== null && $typedFiltered->marker !== null) {
        $harness->setMarker($typedFiltered->marker);
    }
    $rawFiltered = $harness->apiCase($report, 'bot.get_updates_raw_with_types(message_callback)',
        fn(Bot $b) => $b->getUpdatesRawWithTypes($harness->marker(), 1, 1, ['message_callback']));
    if ($rawFiltered !== null && $rawFiltered->marker !== null) {
        $harness->setMarker($rawFiltered->marker);
    }
} else {
    $reason = tr($lang, 'live test is running in webhook transport mode', 'live-тест запущен в webhook-режиме транспорта');
    $report->skip('bot.get_updates', $reason);
    $report->skip('bot.get_updates_raw', $reason);
    $report->skip('bot.get_updates_with_types(message_created)', $reason);
    $report->skip('bot.get_updates_raw_with_types(message_callback)', $reason);
}

// Run all phases
$privateState = runPrivatePhase($harness, $report, $cfg);
runUploadPhase($harness, $report, $cfg, $privateState['chat_id'], $privateState['user_id']);
if ($cfg['transport'] === TRANSPORT_LONG_POLLING) {
    runWebhookPhase($harness, $report, $cfg);
} else {
    $reason = tr($lang, 'webhook transport subscription is kept active for manual waits', 'webhook transport subscription оставлена активной для ручных ожиданий');
    $report->skip('bot.get_subscriptions', $reason);
    $report->skip('bot.subscribe', $reason);
    $report->skip('bot.unsubscribe', $reason);
}
runCommandsPhase($harness, $report, $lang);
runGroupPhase($harness, $report, $cfg, $knownChats, $privateState['user_id']);
if ($privateState['chat_id'] !== null) {
    runOptionalDialogEventsPhase($harness, $report, $cfg, $privateState['chat_id']);
}

restoreDisabledWebhooks($harness, $report, $cfg, $disabledWebhookSubscriptions);

$report->printSummary($lang);
