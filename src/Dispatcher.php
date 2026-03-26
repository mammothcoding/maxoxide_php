<?php

declare(strict_types=1);

namespace Maxoxide;

/**
 * Context passed to every handler.
 * Holds the Bot and the raw Update that triggered the handler.
 */
class Context
{
    public Bot    $bot;
    public Update $update;

    public function __construct(Bot $bot, Update $update)
    {
        $this->bot    = $bot;
        $this->update = $update;
    }
}

/**
 * Routes incoming Updates to registered handlers.
 *
 * Handlers are matched in registration order; the first match wins.
 *
 * Usage:
 *   $dp = new Dispatcher($bot);
 *
 *   $dp->onCommand('/start', function (Context $ctx) {
 *       $ctx->bot->sendTextToChat($ctx->update->message->chatId(), 'Hello!');
 *   });
 *
 *   $dp->onMessage(function (Context $ctx) {
 *       $text = $ctx->update->message->text() ?? '(no text)';
 *       $ctx->bot->sendTextToChat($ctx->update->message->chatId(), $text);
 *   });
 *
 *   $dp->startPolling();
 */
class Dispatcher
{
    private Bot   $bot;
    private array $handlers = [];   // [['filter' => callable, 'handler' => callable], ...]

    /** Called when a handler throws. Default: log to STDERR. */
    private ?\Closure $errorHandler = null;

    /** Long-poll timeout in seconds (max 90). */
    private int $pollTimeout = 30;
    /** Maximum updates per poll request. */
    private int $pollLimit   = 100;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }

    public function setPollTimeout(int $seconds): self { $this->pollTimeout = $seconds; return $this; }
    public function setPollLimit(int $limit): self     { $this->pollLimit   = $limit;   return $this; }

    /** Set a custom error handler; receives the \Throwable that a handler threw. */
    public function onError(callable $handler): self
    {
        $this->errorHandler = \Closure::fromCallable($handler);
        return $this;
    }

    // ─── Handler registration ────────────────────────────────────────────────

    /** Register a handler that fires on ANY update. */
    public function on(callable $handler): self
    {
        return $this->addHandler(static fn(Update $u) => true, $handler);
    }

    /** Register a handler for new messages (message_created). */
    public function onMessage(callable $handler): self
    {
        return $this->addHandler(
            static fn(Update $u) => $u->type === 'message_created',
            $handler
        );
    }

    /** Register a handler for edited messages (message_edited). */
    public function onEditedMessage(callable $handler): self
    {
        return $this->addHandler(
            static fn(Update $u) => $u->type === 'message_edited',
            $handler
        );
    }

    /** Register a handler for any inline button callback (message_callback). */
    public function onCallback(callable $handler): self
    {
        return $this->addHandler(
            static fn(Update $u) => $u->type === 'message_callback',
            $handler
        );
    }

    /** Register a handler for when the bot is first started by a user (bot_started). */
    public function onBotStarted(callable $handler): self
    {
        return $this->addHandler(
            static fn(Update $u) => $u->type === 'bot_started',
            $handler
        );
    }

    /** Register a handler for when the bot is added to a chat (bot_added). */
    public function onBotAdded(callable $handler): self
    {
        return $this->addHandler(
            static fn(Update $u) => $u->type === 'bot_added',
            $handler
        );
    }

    /**
     * Register a handler for a specific bot command (e.g. '/start').
     *
     * Fires when a new message starts with the given command string.
     * Register command handlers before onMessage() — first match wins.
     */
    public function onCommand(string $command, callable $handler): self
    {
        return $this->addHandler(
            static function (Update $u) use ($command): bool {
                if ($u->type !== 'message_created' || $u->message === null) {
                    return false;
                }
                $text = $u->message->text();
                return $text !== null && strpos($text, $command) === 0;
            },
            $handler
        );
    }

    /**
     * Register a handler for a specific callback button payload.
     *
     * Fires when a message_callback update has exactly this payload string.
     * Register before onCallback() — first match wins.
     */
    public function onCallbackPayload(string $payload, callable $handler): self
    {
        return $this->addHandler(
            static function (Update $u) use ($payload): bool {
                return $u->type === 'message_callback'
                    && $u->callback !== null
                    && $u->callback->payload === $payload;
            },
            $handler
        );
    }

    /**
     * Register a handler with a custom filter predicate.
     *
     * The predicate receives the Update and should return true to match.
     */
    public function onFilter(callable $predicate, callable $handler): self
    {
        return $this->addHandler($predicate, $handler);
    }

    // ─── Dispatching ─────────────────────────────────────────────────────────

    /** Dispatch a single update to the first matching handler. */
    public function dispatch(Update $update): void
    {
        foreach ($this->handlers as $entry) {
            if (($entry['filter'])($update)) {
                $ctx = new Context($this->bot, $update);
                try {
                    ($entry['handler'])($ctx);
                } catch (\Throwable $e) {
                    if ($this->errorHandler !== null) {
                        ($this->errorHandler)($e);
                    } else {
                        fwrite(STDERR, "[maxoxide] Handler error: {$e->getMessage()}\n");
                    }
                }
                return; // First match wins.
            }
        }
    }

    // ─── Long polling ─────────────────────────────────────────────────────────

    /**
     * Start the long-polling loop. Runs until the process is killed.
     *
     * On startup it prints the bot username to STDOUT so you know it's alive.
     * On a poll error it waits $retryDelaySec seconds before retrying.
     */
    public function startPolling(int $retryDelaySec = 5): void
    {
        try {
            $me = $this->bot->getMe();
            echo "[maxoxide] Bot @" . ($me->username ?? 'unknown') . " started (long polling)\n";
        } catch (\Throwable $e) {
            fwrite(STDERR, "[maxoxide] Failed to fetch bot info: {$e->getMessage()}\n");
            return;
        }

        $marker = null;

        while (true) {
            try {
                $resp = $this->bot->getUpdates($marker, $this->pollTimeout, $this->pollLimit);
                if ($resp->marker !== null) {
                    $marker = $resp->marker;
                }
                foreach ($resp->updates as $update) {
                    $this->dispatch($update);
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "[maxoxide] Polling error: {$e->getMessage()} — retrying in {$retryDelaySec}s\n");
                sleep($retryDelaySec);
            }
        }
    }

    // ─── Internal ────────────────────────────────────────────────────────────

    private function addHandler(callable $filter, callable $handler): self
    {
        $this->handlers[] = ['filter' => $filter, 'handler' => $handler];
        return $this;
    }
}
