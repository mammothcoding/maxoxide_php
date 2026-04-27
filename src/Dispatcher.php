<?php

declare(strict_types=1);

namespace Maxoxide;

use Closure;
use Throwable;

/**
 * Context passed to every typed update handler.
 */
class Context
{
    public Bot $bot;
    public Update $update;

    public function __construct(Bot $bot, Update $update)
    {
        $this->bot = $bot;
        $this->update = $update;
    }
}

/** Context passed to handlers registered with Dispatcher::onStart(). */
class StartContext
{
    public Bot $bot;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }
}

/** Context passed to periodic task handlers. */
class ScheduledTaskContext
{
    public Bot $bot;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }
}

/** Context passed to raw update handlers. */
class RawUpdateContext
{
    public Bot $bot;
    /** @var array<string, mixed> */
    public array $raw;

    /** @param array<string, mixed> $raw */
    public function __construct(Bot $bot, array $raw)
    {
        $this->bot = $bot;
        $this->raw = $raw;
    }
}

/**
 * Composable update filter used by Dispatcher::onUpdate().
 */
class Filter
{
    /** @var Closure */
    private $matcher;

    private function __construct(callable $matcher)
    {
        $this->matcher = Closure::fromCallable($matcher);
    }

    /** Match any typed update. */
    public static function any(): self
    {
        return new self(static fn(Update $u): bool => true);
    }

    /** Match new message updates. */
    public static function message(): self
    {
        return new self(static fn(Update $u): bool => $u->type === 'message_created');
    }

    /** Match edited message updates. */
    public static function editedMessage(): self
    {
        return new self(static fn(Update $u): bool => $u->type === 'message_edited');
    }

    /** Match callback button updates. */
    public static function callback(): self
    {
        return new self(static fn(Update $u): bool => $u->type === 'message_callback');
    }

    /** Match bot_started updates. */
    public static function botStarted(): self
    {
        return new self(static fn(Update $u): bool => $u->type === 'bot_started');
    }

    /** Match bot_added updates. */
    public static function botAdded(): self
    {
        return new self(static fn(Update $u): bool => $u->type === 'bot_added');
    }

    /** Match new messages whose text starts with a command string. */
    public static function command(string $command): self
    {
        return new self(static function (Update $u) use ($command): bool {
            $message = self::messageFromUpdate($u);
            $text = $u->type === 'message_created' && $message !== null ? $message->text() : null;

            return $text !== null && strpos($text, $command) === 0;
        });
    }

    /** Match callback updates whose payload equals the given string. */
    public static function callbackPayload(string $payload): self
    {
        return new self(static function (Update $u) use ($payload): bool {
            return $u->type === 'message_callback'
                && $u->callback !== null
                && $u->callback->payload === $payload;
        });
    }

    /** Match updates carrying a message in a given chat. */
    public static function chat(int $chatId): self
    {
        return new self(static function (Update $u) use ($chatId): bool {
            $message = self::messageFromUpdate($u);

            return $message !== null && $message->chatId() === $chatId;
        });
    }

    /** Match updates carrying a message from a given sender user ID. */
    public static function sender(int $userId): self
    {
        return new self(static function (Update $u) use ($userId): bool {
            $message = self::messageFromUpdate($u);

            return $message !== null && $message->senderUserId() === $userId;
        });
    }

    /** Match updates carrying a message whose text exactly equals the given string. */
    public static function textExact(string $text): self
    {
        return new self(static function (Update $u) use ($text): bool {
            $message = self::messageFromUpdate($u);

            return $message !== null && $message->text() === $text;
        });
    }

    /** Match updates carrying a message whose text contains the given string. */
    public static function textContains(string $text): self
    {
        return new self(static function (Update $u) use ($text): bool {
            $message = self::messageFromUpdate($u);
            $messageText = $message !== null ? $message->text() : null;

            return $messageText !== null && strpos($messageText, $text) !== false;
        });
    }

    /** Match updates carrying a message whose text matches a PCRE pattern. */
    public static function textRegex(string $pattern): self
    {
        $phpPattern = self::normalizeRegexPattern($pattern);
        if (@preg_match($phpPattern, '') === false) {
            throw new MaxException("Invalid regex filter: {$pattern}");
        }

        return new self(static function (Update $u) use ($phpPattern): bool {
            $message = self::messageFromUpdate($u);
            $messageText = $message !== null ? $message->text() : null;

            return $messageText !== null && preg_match($phpPattern, $messageText) === 1;
        });
    }

    /** Match updates carrying a message with at least one attachment. */
    public static function hasAttachment(): self
    {
        return new self(static function (Update $u): bool {
            $message = self::messageFromUpdate($u);

            return $message !== null && $message->hasAttachments();
        });
    }

    /** Match updates carrying a message with an attachment of the given kind. */
    public static function hasAttachmentType(string $kind): self
    {
        return new self(static fn(Update $u): bool => self::messageHasAttachmentKind($u, $kind));
    }

    /** Match updates carrying a file attachment. */
    public static function hasFile(): self
    {
        return self::hasAttachmentType(AttachmentKind::FILE);
    }

    /** Match updates carrying an image, video, or audio attachment. */
    public static function hasMedia(): self
    {
        return new self(static function (Update $u): bool {
            return self::messageHasAttachmentKind($u, AttachmentKind::IMAGE)
                || self::messageHasAttachmentKind($u, AttachmentKind::VIDEO)
                || self::messageHasAttachmentKind($u, AttachmentKind::AUDIO);
        });
    }

    /** Match unknown future updates. */
    public static function unknownUpdate(): self
    {
        return new self(static fn(Update $u): bool => $u->raw() !== null);
    }

    /** Build a filter from a custom predicate. */
    public static function custom(callable $predicate): self
    {
        return new self($predicate);
    }

    /** Return a filter that matches when both filters match. */
    public function andFilter(Filter $other): self
    {
        return new self(function (Update $u) use ($other): bool {
            return $this->matches($u) && $other->matches($u);
        });
    }

    /** Return a filter that matches when either filter matches. */
    public function orFilter(Filter $other): self
    {
        return new self(function (Update $u) use ($other): bool {
            return $this->matches($u) || $other->matches($u);
        });
    }

    /** Return a filter that negates this filter. */
    public function negate(): self
    {
        return new self(function (Update $u): bool {
            return !$this->matches($u);
        });
    }

    /** Evaluate this filter against an update. */
    public function matches(Update $update): bool
    {
        return (bool) ($this->matcher)($update);
    }

    /** Return the message carried by an update, if any. */
    private static function messageFromUpdate(Update $update): ?Message
    {
        $message = null;
        if (($update->type === 'message_created' || $update->type === 'message_edited') && $update->message !== null) {
            $message = $update->message;
        } elseif ($update->type === 'message_callback' && $update->message !== null) {
            $message = $update->message;
        }

        return $message;
    }

    /** Return true when an update carries an attachment of the given kind. */
    private static function messageHasAttachmentKind(Update $update, string $kind): bool
    {
        $message = self::messageFromUpdate($update);
        $matched = false;
        if ($message !== null) {
            foreach ($message->body->attachments as $attachment) {
                if ($attachment->kind() === $kind) {
                    $matched = true;
                    break;
                }
            }
        }

        return $matched;
    }

    /** Convert a raw Rust-style regex string to a PHP PCRE pattern when needed. */
    private static function normalizeRegexPattern(string $pattern): string
    {
        $delimited = false;
        $first = substr($pattern, 0, 1);
        if ($first !== '') {
            $pos = strrpos($pattern, $first);
            $delimited = in_array($first, ['/', '#', '~', '%'], true) && $pos !== false && $pos > 0;
        }
        $phpPattern = $delimited ? $pattern : '~' . str_replace('~', '\~', $pattern) . '~u';

        return $phpPattern;
    }
}

/**
 * Routes incoming Updates to registered handlers.
 *
 * Typed handlers are matched in registration order; the first match wins.
 * Raw handlers receive every raw JSON update before typed parsing.
 */
class Dispatcher
{
    private Bot $bot;
    /** @var array<int, array{filter: Filter, handler: Closure}> */
    private array $handlers = [];
    /** @var Closure[] */
    private array $startHandlers = [];
    /** @var Closure[] */
    private array $rawUpdateHandlers = [];
    /** @var array<int, array{interval: int, next_run: int, handler: Closure}> */
    private array $scheduledTasks = [];
    private ?Closure $errorHandler = null;
    private int $pollTimeout = 30;
    private int $pollLimit = 100;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }

    /** Set long-poll timeout in seconds. */
    public function setPollTimeout(int $seconds): self
    {
        $this->pollTimeout = $seconds;

        return $this;
    }

    /** Set max updates per poll request. */
    public function setPollLimit(int $limit): self
    {
        $this->pollLimit = $limit;

        return $this;
    }

    /** Set a custom error handler; receives the Throwable that a handler threw. */
    public function onError(callable $handler): self
    {
        $this->errorHandler = Closure::fromCallable($handler);

        return $this;
    }

    /** Register a handler that fires on any typed update. */
    public function on(callable $handler): self
    {
        return $this->onUpdate(Filter::any(), $handler);
    }

    /** Register a handler with an explicit Filter object. */
    public function onUpdate(Filter $filter, callable $handler): self
    {
        $this->handlers[] = ['filter' => $filter, 'handler' => Closure::fromCallable($handler)];

        return $this;
    }

    /** Register a handler for new messages. */
    public function onMessage(callable $handler): self
    {
        return $this->onUpdate(Filter::message(), $handler);
    }

    /** Register a handler for edited messages. */
    public function onEditedMessage(callable $handler): self
    {
        return $this->onUpdate(Filter::editedMessage(), $handler);
    }

    /** Register a handler for any inline button callback. */
    public function onCallback(callable $handler): self
    {
        return $this->onUpdate(Filter::callback(), $handler);
    }

    /** Register a handler for when the bot is first started by a user. */
    public function onBotStarted(callable $handler): self
    {
        return $this->onUpdate(Filter::botStarted(), $handler);
    }

    /** Register a handler for when the bot is added to a chat. */
    public function onBotAdded(callable $handler): self
    {
        return $this->onUpdate(Filter::botAdded(), $handler);
    }

    /** Register a handler for a specific bot command. */
    public function onCommand(string $command, callable $handler): self
    {
        return $this->onUpdate(Filter::command($command), $handler);
    }

    /** Register a handler for a specific callback button payload. */
    public function onCallbackPayload(string $payload, callable $handler): self
    {
        return $this->onUpdate(Filter::callbackPayload($payload), $handler);
    }

    /** Register a handler with a custom filter predicate. */
    public function onFilter(callable $predicate, callable $handler): self
    {
        return $this->onUpdate(Filter::custom($predicate), $handler);
    }

    /** Register a handler that runs once before polling starts. */
    public function onStart(callable $handler): self
    {
        $this->startHandlers[] = Closure::fromCallable($handler);

        return $this;
    }

    /** Register a periodic task that runs while polling is active. */
    public function task(int $intervalSeconds, callable $handler): self
    {
        $this->scheduledTasks[] = [
            'interval' => $intervalSeconds,
            'next_run' => time() + $intervalSeconds,
            'handler' => Closure::fromCallable($handler),
        ];

        return $this;
    }

    /** Register a handler that receives raw JSON for every incoming update. */
    public function onRawUpdate(callable $handler): self
    {
        $this->rawUpdateHandlers[] = Closure::fromCallable($handler);

        return $this;
    }

    /** Dispatch a raw JSON update through raw handlers and typed handlers. */
    public function dispatchRaw(array $raw): void
    {
        foreach ($this->rawUpdateHandlers as $handler) {
            $this->invokeRawHandler($handler, $raw);
        }

        $this->dispatch(Update::fromArray($raw));
    }

    /** Dispatch a single typed update to the first matching handler. */
    public function dispatch(Update $update): void
    {
        foreach ($this->handlers as $entry) {
            if ($entry['filter']->matches($update)) {
                $ctx = new Context($this->bot, $update);
                $this->invokeTypedHandler($entry['handler'], $ctx);
                break;
            }
        }
    }

    /**
     * Start the long-polling loop. Runs until the process is killed.
     *
     * On startup it prints the bot username to STDOUT so you know it is alive.
     */
    public function startPolling(int $retryDelaySec = 5): void
    {
        try {
            $me = $this->bot->getMe();
            echo '[maxoxide] Bot @' . ($me->username ?? 'unknown') . " started (long polling)\n";
        } catch (Throwable $e) {
            fwrite(STDERR, "[maxoxide] Failed to fetch bot info: {$e->getMessage()}\n");
            return;
        }

        $this->runStartHandlers();
        $marker = null;

        while (true) {
            $this->runDueTasks();
            try {
                $resp = $this->bot->getUpdatesRaw($marker, $this->pollTimeout, $this->pollLimit);
                if ($resp->marker !== null) {
                    $marker = $resp->marker;
                }
                foreach ($resp->updates as $rawUpdate) {
                    $this->dispatchRaw($rawUpdate);
                }
            } catch (Throwable $e) {
                fwrite(STDERR, "[maxoxide] Polling error: {$e->getMessage()} - retrying in {$retryDelaySec}s\n");
                sleep($retryDelaySec);
            }
        }
    }

    /** Invoke a typed handler and route any thrown error. */
    private function invokeTypedHandler(Closure $handler, Context $ctx): void
    {
        try {
            $handler($ctx);
        } catch (Throwable $e) {
            $this->handleError($e);
        }
    }

    /** Invoke a raw update handler and route any thrown error. */
    private function invokeRawHandler(Closure $handler, array $raw): void
    {
        try {
            $handler(new RawUpdateContext($this->bot, $raw));
        } catch (Throwable $e) {
            $this->handleError($e);
        }
    }

    /** Run startup handlers before polling begins. */
    private function runStartHandlers(): void
    {
        foreach ($this->startHandlers as $handler) {
            try {
                $handler(new StartContext($this->bot));
            } catch (Throwable $e) {
                $this->handleError($e);
            }
        }
    }

    /** Run scheduled tasks whose interval has elapsed. */
    private function runDueTasks(): void
    {
        $now = time();
        foreach ($this->scheduledTasks as &$task) {
            if ($now >= $task['next_run']) {
                $task['next_run'] = $now + $task['interval'];
                try {
                    $task['handler'](new ScheduledTaskContext($this->bot));
                } catch (Throwable $e) {
                    $this->handleError($e);
                }
            }
        }
        unset($task);
    }

    /** Dispatch handler errors to a custom handler or STDERR. */
    private function handleError(Throwable $e): void
    {
        if ($this->errorHandler !== null) {
            ($this->errorHandler)($e);
        } else {
            fwrite(STDERR, "[maxoxide] Handler error: {$e->getMessage()}\n");
        }
    }
}
