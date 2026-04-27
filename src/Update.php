<?php

declare(strict_types=1);

namespace Maxoxide;

/**
 * A single update event received from the Max platform via long polling or webhook.
 *
 * Unknown future updates are preserved with their raw JSON instead of failing
 * typed parsing. Existing code can keep reading `$update->type`.
 */
class Update
{
    public string $type;
    public ?int $timestamp;
    public ?string $wireType = null;
    /** @var array<string, mixed>|null */
    public ?array $raw = null;

    // message_created / message_edited
    public ?Message $message = null;

    // message_removed
    public ?string $messageId = null;
    public ?int $chatId = null;
    public ?int $userId = null;

    // message_callback
    public ?Callback $callback = null;
    public ?string $userLocale = null;

    // bot_started / bot_added / bot_removed / user_added / user_removed / chat_title_changed
    public ?User $user = null;
    public ?string $payload = null;
    public ?bool $isChannel = null;
    public ?int $inviterId = null;
    public ?int $adminId = null;
    public ?string $title = null;

    private function __construct(string $type, ?int $timestamp, ?string $wireType = null)
    {
        $this->type = $type;
        $this->timestamp = $timestamp;
        $this->wireType = $wireType;
    }

    /** Parse a raw associative array into a forward-compatible Update. */
    public static function fromArray(array $d): self
    {
        $wireType = isset($d['update_type']) ? (string) $d['update_type'] : null;
        $timestamp = isset($d['timestamp']) ? (int) $d['timestamp'] : null;
        $type = $wireType ?? 'unknown';
        $u = new self($type, $timestamp, $wireType);

        switch ($type) {
            case 'message_created':
            case 'message_edited':
                if (isset($d['message']) && is_array($d['message'])) {
                    $u->message = Message::fromArray($d['message']);
                } else {
                    $u = self::unknown($wireType, $timestamp, $d);
                }
                break;

            case 'message_removed':
                $u->messageId = isset($d['message_id']) ? (string) $d['message_id'] : null;
                $u->chatId = isset($d['chat_id']) ? (int) $d['chat_id'] : null;
                $u->userId = isset($d['user_id']) ? (int) $d['user_id'] : null;
                break;

            case 'message_callback':
                if (isset($d['callback']) && is_array($d['callback'])) {
                    $u->callback = Callback::fromArray($d['callback']);
                    $u->message = isset($d['message']) && is_array($d['message']) ? Message::fromArray($d['message']) : null;
                    $u->userLocale = isset($d['user_locale']) ? (string) $d['user_locale'] : null;
                } else {
                    $u = self::unknown($wireType, $timestamp, $d);
                }
                break;

            case 'bot_started':
                if (isset($d['user']) && is_array($d['user'])) {
                    $u->chatId = isset($d['chat_id']) ? (int) $d['chat_id'] : null;
                    $u->user = User::fromArray($d['user']);
                    $u->payload = isset($d['payload']) ? (string) $d['payload'] : null;
                    $u->userLocale = isset($d['user_locale']) ? (string) $d['user_locale'] : null;
                } else {
                    $u = self::unknown($wireType, $timestamp, $d);
                }
                break;

            case 'bot_added':
            case 'bot_removed':
                if (isset($d['user']) && is_array($d['user'])) {
                    $u->chatId = isset($d['chat_id']) ? (int) $d['chat_id'] : null;
                    $u->user = User::fromArray($d['user']);
                    $u->isChannel = isset($d['is_channel']) ? (bool) $d['is_channel'] : null;
                } else {
                    $u = self::unknown($wireType, $timestamp, $d);
                }
                break;

            case 'user_added':
                if (isset($d['user']) && is_array($d['user'])) {
                    $u->chatId = isset($d['chat_id']) ? (int) $d['chat_id'] : null;
                    $u->user = User::fromArray($d['user']);
                    $u->inviterId = isset($d['inviter_id']) ? (int) $d['inviter_id'] : null;
                    $u->isChannel = isset($d['is_channel']) ? (bool) $d['is_channel'] : null;
                } else {
                    $u = self::unknown($wireType, $timestamp, $d);
                }
                break;

            case 'user_removed':
                if (isset($d['user']) && is_array($d['user'])) {
                    $u->chatId = isset($d['chat_id']) ? (int) $d['chat_id'] : null;
                    $u->user = User::fromArray($d['user']);
                    $u->adminId = isset($d['admin_id']) ? (int) $d['admin_id'] : null;
                    $u->isChannel = isset($d['is_channel']) ? (bool) $d['is_channel'] : null;
                } else {
                    $u = self::unknown($wireType, $timestamp, $d);
                }
                break;

            case 'chat_title_changed':
                if (isset($d['user']) && is_array($d['user'])) {
                    $u->chatId = isset($d['chat_id']) ? (int) $d['chat_id'] : null;
                    $u->user = User::fromArray($d['user']);
                    $u->title = isset($d['title']) ? (string) $d['title'] : null;
                } else {
                    $u = self::unknown($wireType, $timestamp, $d);
                }
                break;

            default:
                $u = self::unknown($wireType, $timestamp, $d);
        }

        return $u;
    }

    /** Build an unknown update preserving raw JSON for inspection. */
    private static function unknown(?string $wireType, ?int $timestamp, array $raw): self
    {
        $u = new self($wireType ?? 'unknown', $timestamp, $wireType);
        $u->raw = $raw;

        return $u;
    }

    /** Return the timestamp when one was present. */
    public function timestamp(): ?int
    {
        return $this->timestamp;
    }

    /** Return the timestamp or 0 for unknown updates without one. */
    public function timestampOrDefault(): int
    {
        return $this->timestamp ?? 0;
    }

    /** Return the MAX wire update type, if present. */
    public function updateType(): ?string
    {
        return $this->wireType;
    }

    /** Return raw JSON for unknown updates. */
    public function raw(): ?array
    {
        return $this->raw;
    }
}

/** An inline button callback event. */
class Callback
{
    public string $callbackId;
    public User $user;
    public ?string $payload;
    public int $timestamp;

    public function __construct(string $callbackId, User $user, int $timestamp, ?string $payload = null)
    {
        $this->callbackId = $callbackId;
        $this->user = $user;
        $this->payload = $payload;
        $this->timestamp = $timestamp;
    }

    /** Parse callback data from a MAX update. */
    public static function fromArray(array $d): self
    {
        return new self(
            (string) ($d['callback_id'] ?? ''),
            User::fromArray((array) ($d['user'] ?? [])),
            (int) ($d['timestamp'] ?? 0),
            isset($d['payload']) ? (string) $d['payload'] : null
        );
    }
}

/** Typed container returned by GET /updates. */
class UpdatesResponse
{
    /** @var Update[] */
    public array $updates;
    public ?int $marker;

    /** @param Update[] $updates */
    public function __construct(array $updates, ?int $marker = null)
    {
        $this->updates = $updates;
        $this->marker = $marker;
    }

    /** Parse a typed updates response. */
    public static function fromArray(array $d): self
    {
        return new self(
            array_map(static fn(array $u) => Update::fromArray($u), $d['updates'] ?? []),
            isset($d['marker']) ? (int) $d['marker'] : null
        );
    }
}

/** Raw container returned by GET /updates before typed update parsing. */
class RawUpdatesResponse
{
    /** @var array<int, array<string, mixed>> */
    public array $updates;
    public ?int $marker;

    /** @param array<int, array<string, mixed>> $updates */
    public function __construct(array $updates, ?int $marker = null)
    {
        $this->updates = $updates;
        $this->marker = $marker;
    }

    /** Parse a raw updates response. */
    public static function fromArray(array $d): self
    {
        $updates = [];
        foreach ((array) ($d['updates'] ?? []) as $update) {
            if (is_array($update)) {
                $updates[] = $update;
            }
        }

        return new self($updates, isset($d['marker']) ? (int) $d['marker'] : null);
    }
}
