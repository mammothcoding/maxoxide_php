<?php

declare(strict_types=1);

namespace Maxoxide;

/**
 * A single update event received from the Max platform
 * (via long polling or webhook).
 *
 * The `type` field identifies the event:
 *   message_created    → message
 *   message_edited     → message
 *   message_removed    → messageId, chatId, userId
 *   message_callback   → callback, message (optional)
 *   bot_started        → chatId, user, payload (optional)
 *   bot_added          → chatId, user, isChannel
 *   bot_removed        → chatId, user, isChannel
 *   user_added         → chatId, user, inviterId (optional)
 *   user_removed       → chatId, user, adminId (optional)
 *   chat_title_changed → chatId, user, title
 */
class Update
{
    public string $type;
    public int    $timestamp;

    // message_created / message_edited
    public ?Message $message = null;

    // message_removed
    public ?string $messageId = null;
    public ?int    $chatId    = null;
    public ?int    $userId    = null;

    // message_callback
    public ?Callback $callback    = null;
    public ?string   $userLocale  = null;

    // bot_started / bot_added / bot_removed / user_added / user_removed / chat_title_changed
    public ?User   $user       = null;
    public ?string $payload    = null;  // bot_started
    public ?bool   $isChannel  = null;  // bot_added / bot_removed / user_added / user_removed
    public ?int    $inviterId  = null;  // user_added
    public ?int    $adminId    = null;  // user_removed
    public ?string $title      = null;  // chat_title_changed

    private function __construct(string $type, int $timestamp)
    {
        $this->type      = $type;
        $this->timestamp = $timestamp;
    }

    /** Parse a raw associative array (decoded JSON) into an Update. */
    public static function fromArray(array $d): self
    {
        $type = (string) ($d['update_type'] ?? 'unknown');
        $u    = new self($type, (int) $d['timestamp']);

        switch ($type) {
            case 'message_created':
            case 'message_edited':
                $u->message = Message::fromArray((array) $d['message']);
                break;

            case 'message_removed':
                $u->messageId = (string) $d['message_id'];
                $u->chatId    = (int)    $d['chat_id'];
                $u->userId    = (int)    $d['user_id'];
                break;

            case 'message_callback':
                $u->callback   = Callback::fromArray((array) $d['callback']);
                $u->message    = isset($d['message'])     ? Message::fromArray((array) $d['message']) : null;
                $u->userLocale = isset($d['user_locale']) ? (string) $d['user_locale']               : null;
                break;

            case 'bot_started':
                $u->chatId     = (int) $d['chat_id'];
                $u->user       = User::fromArray((array) $d['user']);
                $u->payload    = isset($d['payload'])     ? (string) $d['payload']     : null;
                $u->userLocale = isset($d['user_locale']) ? (string) $d['user_locale'] : null;
                break;

            case 'bot_added':
            case 'bot_removed':
                $u->chatId    = (int) $d['chat_id'];
                $u->user      = User::fromArray((array) $d['user']);
                $u->isChannel = isset($d['is_channel']) ? (bool) $d['is_channel'] : null;
                break;

            case 'user_added':
                $u->chatId    = (int) $d['chat_id'];
                $u->user      = User::fromArray((array) $d['user']);
                $u->inviterId = isset($d['inviter_id'])  ? (int)  $d['inviter_id']  : null;
                $u->isChannel = isset($d['is_channel'])  ? (bool) $d['is_channel']  : null;
                break;

            case 'user_removed':
                $u->chatId    = (int) $d['chat_id'];
                $u->user      = User::fromArray((array) $d['user']);
                $u->adminId   = isset($d['admin_id'])    ? (int)  $d['admin_id']    : null;
                $u->isChannel = isset($d['is_channel'])  ? (bool) $d['is_channel']  : null;
                break;

            case 'chat_title_changed':
                $u->chatId = (int)    $d['chat_id'];
                $u->user   = User::fromArray((array) $d['user']);
                $u->title  = (string) $d['title'];
                break;
        }

        return $u;
    }
}

/** An inline button callback event. */
class Callback
{
    public string  $callbackId;
    public User    $user;
    public ?string $payload;
    public int     $timestamp;

    public function __construct(string $callbackId, User $user, int $timestamp, ?string $payload = null)
    {
        $this->callbackId = $callbackId;
        $this->user       = $user;
        $this->payload    = $payload;
        $this->timestamp  = $timestamp;
    }

    public static function fromArray(array $d): self
    {
        return new self(
            (string) $d['callback_id'],
            User::fromArray((array) $d['user']),
            (int) $d['timestamp'],
            isset($d['payload']) ? (string) $d['payload'] : null
        );
    }
}

/** Container returned by GET /updates. */
class UpdatesResponse
{
    /** @var Update[] */
    public array $updates;
    public ?int  $marker;

    public function __construct(array $updates, ?int $marker = null)
    {
        $this->updates = $updates;
        $this->marker  = $marker;
    }

    public static function fromArray(array $d): self
    {
        return new self(
            array_map(static fn(array $u) => Update::fromArray($u), $d['updates'] ?? []),
            isset($d['marker']) ? (int) $d['marker'] : null
        );
    }
}
