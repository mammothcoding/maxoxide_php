<?php

declare(strict_types=1);

namespace Maxoxide;

// ─────────────────────────────────────────────────────────────────────────────
// User / Bot info
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Represents a Max user or bot.
 *
 * NOTE: Do not confuse `userId` with `chatId`.
 * A user can appear in different dialogs/groups, each with its own `chatId`.
 */
class User
{
    /** Global MAX user identifier. */
    public int $userId;
    public string $name;
    public ?string $username;
    public ?bool $isBot;
    public ?int $lastActivityTime;
    public ?string $avatarUrl;
    public ?string $fullAvatarUrl;

    public function __construct(int $userId, string $name)
    {
        $this->userId = $userId;
        $this->name = $name;
    }

    public static function fromArray(array $d): self
    {
        $u = new self((int) $d['user_id'], (string) $d['name']);
        $u->username = isset($d['username']) ? (string) $d['username'] : null;
        $u->isBot = isset($d['is_bot']) ? (bool) $d['is_bot'] : null;
        $u->lastActivityTime = isset($d['last_activity_time']) ? (int) $d['last_activity_time'] : null;
        $u->avatarUrl = isset($d['avatar_url']) ? (string) $d['avatar_url'] : null;
        $u->fullAvatarUrl = isset($d['full_avatar_url']) ? (string) $d['full_avatar_url'] : null;
        return $u;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Chat
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Represents a Max chat (dialog, group, or channel).
 *
 * NOTE: `chatId` identifies the concrete dialog/group, not the user.
 */
class Chat
{
    public int $chatId;
    /** 'dialog' | 'chat' | 'channel' */
    public string $type;
    public ?string $status   = null;
    public ?string $title    = null;
    public ?string $iconUrl  = null;
    public ?int    $lastEventTime      = null;
    public ?int    $participantsCount  = null;
    public ?int    $ownerId            = null;
    public ?bool   $isPublic           = null;
    public ?string $link               = null;
    public ?string $description        = null;

    public function __construct(int $chatId, string $type)
    {
        $this->chatId = $chatId;
        $this->type   = $type;
    }

    public static function fromArray(array $d): self
    {
        $c = new self((int) $d['chat_id'], (string) $d['type']);
        $c->status          = isset($d['status'])             ? (string) $d['status']                       : null;
        $c->title           = isset($d['title'])              ? (string) $d['title']                        : null;
        $c->iconUrl         = isset($d['icon']['url'])        ? (string) $d['icon']['url']                  : null;
        $c->lastEventTime   = isset($d['last_event_time'])    ? (int)    $d['last_event_time']              : null;
        $c->participantsCount = isset($d['participants_count']) ? (int) $d['participants_count']            : null;
        $c->ownerId         = isset($d['owner_id'])           ? (int)    $d['owner_id']                     : null;
        $c->isPublic        = isset($d['is_public'])          ? (bool)   $d['is_public']                    : null;
        $c->link            = isset($d['link'])               ? (string) $d['link']                         : null;
        $c->description     = isset($d['description'])        ? (string) $d['description']                  : null;
        return $c;
    }
}

/** Response from GET /chats */
class ChatList
{
    /** @var Chat[] */
    public array $chats;
    public ?int $marker;

    public function __construct(array $chats, ?int $marker = null)
    {
        $this->chats  = $chats;
        $this->marker = $marker;
    }

    public static function fromArray(array $d): self
    {
        return new self(
            array_map(static fn(array $c) => Chat::fromArray($c), $d['chats'] ?? []),
            isset($d['marker']) ? (int) $d['marker'] : null
        );
    }
}

/** Body for PATCH /chats/{chatId} */
class EditChatBody
{
    public ?string $title       = null;
    public ?string $description = null;
    public ?string $pin         = null;
    public ?bool   $notify      = null;

    public function toArray(): array
    {
        return array_filter([
            'title'       => $this->title,
            'description' => $this->description,
            'pin'         => $this->pin,
            'notify'      => $this->notify,
        ], static fn($v) => $v !== null);
    }
}

/** Chat member info (from GET /chats/{id}/members). */
class ChatMember
{
    public int $userId;
    public string $name;
    public ?string $username        = null;
    public ?string $avatarUrl       = null;
    public ?bool   $isOwner         = null;
    public ?bool   $isAdmin         = null;
    public ?int    $joinTime        = null;
    /** @var string[]|null */
    public ?array  $permissions     = null;
    public ?int    $lastAccessTime  = null;
    public ?bool   $isBot           = null;

    public function __construct(int $userId, string $name)
    {
        $this->userId = $userId;
        $this->name   = $name;
    }

    public static function fromArray(array $d): self
    {
        $m = new self((int) $d['user_id'], (string) $d['name']);
        $m->username       = isset($d['username'])         ? (string) $d['username']        : null;
        $m->avatarUrl      = isset($d['avatar_url'])       ? (string) $d['avatar_url']      : null;
        $m->isOwner        = isset($d['is_owner'])         ? (bool)   $d['is_owner']        : null;
        $m->isAdmin        = isset($d['is_admin'])         ? (bool)   $d['is_admin']        : null;
        $m->joinTime       = isset($d['join_time'])        ? (int)    $d['join_time']       : null;
        $m->permissions    = isset($d['permissions'])      ? (array)  $d['permissions']     : null;
        $m->lastAccessTime = isset($d['last_access_time']) ? (int)    $d['last_access_time'] : null;
        $m->isBot          = isset($d['is_bot'])           ? (bool)   $d['is_bot']          : null;
        return $m;
    }
}

/** Response from GET /chats/{id}/members */
class ChatMembersList
{
    /** @var ChatMember[] */
    public array $members;
    public ?int $marker;

    public function __construct(array $members, ?int $marker = null)
    {
        $this->members = $members;
        $this->marker  = $marker;
    }

    public static function fromArray(array $d): self
    {
        return new self(
            array_map(static fn(array $m) => ChatMember::fromArray($m), $d['members'] ?? []),
            isset($d['marker']) ? (int) $d['marker'] : null
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Message
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Target chat metadata attached to a received message.
 *
 * In private dialogs `chatId` is the dialog ID; `userId` is the peer's global ID.
 * Never mix up `chatId` and `userId`.
 */
class Recipient
{
    public int $chatId;
    /** 'dialog' | 'chat' | 'channel' */
    public string $chatType;
    public ?int $userId;

    public function __construct(int $chatId, string $chatType, ?int $userId = null)
    {
        $this->chatId   = $chatId;
        $this->chatType = $chatType;
        $this->userId   = $userId;
    }

    public static function fromArray(array $d): self
    {
        return new self(
            (int)    $d['chat_id'],
            (string) $d['chat_type'],
            isset($d['user_id']) ? (int) $d['user_id'] : null
        );
    }
}

/**
 * An attachment inside a received message.
 *
 * The `type` field determines which payload properties are set:
 *   image/video/audio  → url, token, photoId
 *   file               → url, token, filename, fileSize
 *   sticker            → stickerCode, url, width, height
 *   inline_keyboard    → buttons  (array of Button[])
 *   location           → latitude, longitude
 *   contact            → contactName, contactId, vcfInfo, vcfPhone
 *   unknown            → raw
 */
class Attachment
{
    public string $type;

    public ?string $url      = null;
    public ?string $token    = null;
    public ?int    $photoId  = null;

    public ?string $filename  = null;
    public ?int    $fileSize  = null;

    public ?string $stickerCode = null;
    public ?int    $width       = null;
    public ?int    $height      = null;

    /** @var array<int, Button[]> */
    public array   $buttons  = [];

    public ?float  $latitude  = null;
    public ?float  $longitude = null;

    public ?string $contactName = null;
    public ?int    $contactId   = null;
    public ?string $vcfInfo     = null;
    public ?string $vcfPhone    = null;

    public array   $raw = [];

    public function __construct(string $type)
    {
        $this->type = $type;
    }

    public static function fromArray(array $d): self
    {
        $type = (string) ($d['type'] ?? 'unknown');
        $a    = new self($type);
        $p    = $d['payload'] ?? [];

        switch ($type) {
            case 'image':
            case 'video':
            case 'audio':
                $a->url     = isset($p['url'])      ? (string) $p['url']      : null;
                $a->token   = isset($p['token'])    ? (string) $p['token']    : null;
                $a->photoId = isset($p['photo_id']) ? (int)    $p['photo_id'] : null;
                break;
            case 'file':
                $a->url      = isset($p['url'])      ? (string) $p['url']      : null;
                $a->token    = isset($p['token'])    ? (string) $p['token']    : null;
                $a->filename = isset($p['filename']) ? (string) $p['filename'] : null;
                $a->fileSize = isset($p['size'])     ? (int)    $p['size']     : null;
                break;
            case 'sticker':
                $a->stickerCode = (string) ($p['code'] ?? '');
                $a->url         = isset($p['url'])    ? (string) $p['url']    : null;
                $a->width       = isset($p['width'])  ? (int)    $p['width']  : null;
                $a->height      = isset($p['height']) ? (int)    $p['height'] : null;
                break;
            case 'inline_keyboard':
                foreach ((array) ($p['buttons'] ?? []) as $row) {
                    $a->buttons[] = array_map(
                        static fn(array $b) => Button::fromArray($b),
                        (array) $row
                    );
                }
                break;
            case 'location':
                $a->latitude  = isset($p['latitude'])  ? (float) $p['latitude']  : null;
                $a->longitude = isset($p['longitude']) ? (float) $p['longitude'] : null;
                break;
            case 'contact':
                $a->contactName = isset($p['name'])       ? (string) $p['name']       : null;
                $a->contactId   = isset($p['contact_id']) ? (int)    $p['contact_id'] : null;
                $a->vcfInfo     = isset($p['vcf_info'])   ? (string) $p['vcf_info']   : null;
                $a->vcfPhone    = isset($p['vcf_phone'])  ? (string) $p['vcf_phone']  : null;
                break;
            default:
                $a->raw = $d;
        }
        return $a;
    }
}

class MessageBody
{
    public string  $mid;
    public int     $seq;
    public ?string $text;
    /** @var Attachment[] */
    public array   $attachments;

    public function __construct(string $mid, int $seq, ?string $text = null, array $attachments = [])
    {
        $this->mid         = $mid;
        $this->seq         = $seq;
        $this->text        = $text;
        $this->attachments = $attachments;
    }

    public static function fromArray(array $d): self
    {
        $atts = [];
        foreach ($d['attachments'] ?? [] as $raw) {
            try {
                $atts[] = Attachment::fromArray((array) $raw);
            } catch (\Throwable $e) {
                // Skip unknown/malformed attachments (mirrors Rust lossy deserialization).
            }
        }
        return new self(
            (string) $d['mid'],
            (int)    $d['seq'],
            isset($d['text']) ? (string) $d['text'] : null,
            $atts
        );
    }
}

class Message
{
    public ?User      $sender;
    public Recipient  $recipient;
    public int        $timestamp;
    public MessageBody $body;
    public ?int       $statViews = null;
    public ?string    $url       = null;

    public function __construct(Recipient $recipient, int $timestamp, MessageBody $body, ?User $sender = null)
    {
        $this->recipient  = $recipient;
        $this->timestamp  = $timestamp;
        $this->body       = $body;
        $this->sender     = $sender;
    }

    /**
     * chat_id of the dialog/group/channel.
     * NOT the sender's global userId — never mix these up.
     */
    public function chatId(): int    { return $this->recipient->chatId; }
    public function messageId(): string { return $this->body->mid; }
    public function text(): ?string     { return $this->body->text; }

    public static function fromArray(array $d): self
    {
        $msg = new self(
            Recipient::fromArray((array) $d['recipient']),
            (int) $d['timestamp'],
            MessageBody::fromArray((array) $d['body']),
            isset($d['sender']) ? User::fromArray((array) $d['sender']) : null
        );
        $msg->statViews = isset($d['stat']['views']) ? (int) $d['stat']['views'] : null;
        $msg->url       = isset($d['url'])           ? (string) $d['url']        : null;
        return $msg;
    }
}

/** Response from GET /messages */
class MessageList
{
    /** @var Message[] */
    public array $messages;

    public function __construct(array $messages) { $this->messages = $messages; }

    public static function fromArray(array $d): self
    {
        return new self(
            array_map(static fn(array $m) => Message::fromArray($m), $d['messages'] ?? [])
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Keyboard / Buttons
// ─────────────────────────────────────────────────────────────────────────────

/**
 * An inline keyboard button.
 *
 * Quick factory methods:
 *   Button::callback('text', 'payload')
 *   Button::link('text', 'https://...')
 *   Button::message('text')
 *   Button::requestContact('text')
 *   Button::requestGeoLocation('text')
 *
 * Intents: 'default' | 'positive' | 'negative'
 */
class Button
{
    /** 'callback' | 'link' | 'message' | 'request_contact' | 'request_geo_location' */
    public string  $type;
    public string  $text;
    public ?string $payload = null;
    public ?string $url     = null;
    public ?string $intent  = null;
    public ?bool   $quick   = null;

    public function __construct(string $type, string $text)
    {
        $this->type = $type;
        $this->text = $text;
    }

    public static function callback(string $text, string $payload, ?string $intent = null): self
    {
        $b = new self('callback', $text);
        $b->payload = $payload;
        $b->intent  = $intent;
        return $b;
    }

    public static function link(string $text, string $url, ?string $intent = null): self
    {
        $b = new self('link', $text);
        $b->url    = $url;
        $b->intent = $intent;
        return $b;
    }

    /**
     * Button that sends a text message as the user.
     * The button label is also the sent text.
     */
    public static function message(string $text, ?string $intent = null): self
    {
        $b = new self('message', $text);
        $b->intent = $intent;
        return $b;
    }

    /**
     * Requests the user's contact card.
     * NOTE: live MAX tests show empty contact_id/vcf_phone — phone not guaranteed.
     */
    public static function requestContact(string $text): self
    {
        return new self('request_contact', $text);
    }

    /**
     * Requests the user's geo location.
     * NOTE: end-to-end delivery not confirmed on the MAX side.
     */
    public static function requestGeoLocation(string $text, ?bool $quick = null): self
    {
        $b = new self('request_geo_location', $text);
        $b->quick = $quick;
        return $b;
    }

    public function toArray(): array
    {
        $d = ['type' => $this->type, 'text' => $this->text];
        if ($this->payload !== null) { $d['payload'] = $this->payload; }
        if ($this->url     !== null) { $d['url']     = $this->url;     }
        if ($this->intent  !== null) { $d['intent']  = $this->intent;  }
        if ($this->quick   !== null) { $d['quick']   = $this->quick;   }
        return $d;
    }

    public static function fromArray(array $d): self
    {
        $b = new self((string) ($d['type'] ?? 'callback'), (string) ($d['text'] ?? ''));
        $b->payload = isset($d['payload']) ? (string) $d['payload'] : null;
        $b->url     = isset($d['url'])     ? (string) $d['url']     : null;
        $b->intent  = isset($d['intent'])  ? (string) $d['intent']  : null;
        $b->quick   = isset($d['quick'])   ? (bool)   $d['quick']   : null;
        return $b;
    }
}

/**
 * An inline keyboard (grid of buttons).
 * Max allows up to 30 rows and 7 buttons per row.
 */
class KeyboardPayload
{
    /** @var array<int, Button[]> */
    public array $buttons;

    /** @param array<int, Button[]> $buttons */
    public function __construct(array $buttons = [])
    {
        $this->buttons = $buttons;
    }

    public function toArray(): array
    {
        return [
            'buttons' => array_map(
                static fn(array $row) => array_map(static fn(Button $b) => $b->toArray(), $row),
                $this->buttons
            ),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Outgoing message
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Attachment to include in an outgoing message.
 * Use the static factory methods.
 */
class NewAttachment
{
    public string $type;
    public array  $payload;

    private function __construct(string $type, array $payload)
    {
        $this->type    = $type;
        $this->payload = $payload;
    }

    public static function inlineKeyboard(KeyboardPayload $keyboard): self
    {
        return new self('inline_keyboard', $keyboard->toArray());
    }

    public static function image(string $token): self  { return new self('image', ['token' => $token]); }
    public static function video(string $token): self  { return new self('video', ['token' => $token]); }
    public static function audio(string $token): self  { return new self('audio', ['token' => $token]); }
    public static function file(string $token): self   { return new self('file',  ['token' => $token]); }

    public function toArray(): array
    {
        return ['type' => $this->type, 'payload' => $this->payload];
    }
}

/**
 * Body for POST /messages (outgoing message).
 *
 * Usage:
 *   NewMessageBody::text('Hello!')
 *   NewMessageBody::text('*Bold*')->withFormat('markdown')->withKeyboard($kb)
 */
class NewMessageBody
{
    public ?string $text        = null;
    /** @var NewAttachment[]|null */
    public ?array  $attachments = null;
    public ?array  $link        = null;
    public ?bool   $notify      = null;
    /** 'markdown' | 'html' | 'plain' */
    public ?string $format      = null;

    public static function text(string $text): self
    {
        $b = new self();
        $b->text = $text;
        return $b;
    }

    /** Set text format: 'markdown' (default), 'html', or 'plain'. */
    public function withFormat(string $format): self { $this->format = $format; return $this; }

    public function withKeyboard(KeyboardPayload $keyboard): self
    {
        return $this->withAttachment(NewAttachment::inlineKeyboard($keyboard));
    }

    public function withAttachment(NewAttachment $att): self
    {
        $this->attachments = $this->attachments ?? [];
        $this->attachments[] = $att;
        return $this;
    }

    /** Link to another message: $type = 'reply' | 'forward', $mid = message ID. */
    public function withLink(string $type, string $mid): self
    {
        $this->link = ['type' => $type, 'mid' => $mid];
        return $this;
    }

    public function withNotify(bool $notify): self { $this->notify = $notify; return $this; }

    public function toArray(): array
    {
        $d = [];
        if ($this->text        !== null) { $d['text']   = $this->text; }
        if ($this->attachments !== null) {
            $d['attachments'] = array_map(static fn(NewAttachment $a) => $a->toArray(), $this->attachments);
        }
        if ($this->link   !== null) { $d['link']   = $this->link;   }
        if ($this->notify !== null) { $d['notify'] = $this->notify; }
        if ($this->format !== null) { $d['format'] = $this->format; }
        return $d;
    }
}

/** Body for POST /answers (reply to inline button press). */
class AnswerCallbackBody
{
    public string  $callbackId;
    public ?NewMessageBody $message      = null;
    public ?string         $notification = null;

    public function __construct(string $callbackId)
    {
        $this->callbackId = $callbackId;
    }

    public function toArray(): array
    {
        $d = [];
        if ($this->message      !== null) { $d['message']      = $this->message->toArray(); }
        if ($this->notification !== null) { $d['notification'] = $this->notification;       }
        return $d;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Misc API responses
// ─────────────────────────────────────────────────────────────────────────────

/** Generic `{"success": true}` response. */
class SimpleResult
{
    public bool    $success;
    public ?string $message;

    public function __construct(bool $success, ?string $message = null)
    {
        $this->success = $success;
        $this->message = $message;
    }

    public static function fromArray(array $d): self
    {
        return new self((bool) $d['success'], isset($d['message']) ? (string) $d['message'] : null);
    }
}

/** Pinned message info (GET /chats/{id}/pin). */
class PinnedMessage
{
    public Message $message;

    public function __construct(Message $message) { $this->message = $message; }

    public static function fromArray(array $d): self
    {
        return new self(Message::fromArray((array) $d['message']));
    }
}

/** Body for PUT /chats/{chatId}/pin */
class PinMessageBody
{
    public string $messageId;
    public ?bool  $notify = null;

    public function __construct(string $messageId, ?bool $notify = null)
    {
        $this->messageId = $messageId;
        $this->notify    = $notify;
    }

    public function toArray(): array
    {
        $d = ['message_id' => $this->messageId];
        if ($this->notify !== null) { $d['notify'] = $this->notify; }
        return $d;
    }
}

/** Bot command entry (used by setMyCommands). */
class BotCommand
{
    public string $name;
    public string $description;

    public function __construct(string $name, string $description)
    {
        $this->name        = $name;
        $this->description = $description;
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description];
    }
}

/** Body for POST /subscriptions (register webhook). */
class SubscribeBody
{
    /** HTTPS URL of your endpoint (port 443, no self-signed certs). */
    public string $url;
    /** @var string[]|null Optional list of update types to receive. */
    public ?array  $updateTypes = null;
    public ?string $version     = null;
    /**
     * Optional shared secret (5–256 chars, [A-Za-z0-9_-]).
     * Sent in the X-Max-Bot-Api-Secret header on every webhook request.
     */
    public ?string $secret      = null;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function toArray(): array
    {
        $d = ['url' => $this->url];
        if ($this->updateTypes !== null) { $d['update_types'] = $this->updateTypes; }
        if ($this->version     !== null) { $d['version']      = $this->version;     }
        if ($this->secret      !== null) { $d['secret']       = $this->secret;      }
        return $d;
    }
}

/** Upload type for POST /uploads. */
class UploadType
{
    const IMAGE = 'image';
    const VIDEO = 'video';
    const AUDIO = 'audio';
    const FILE  = 'file';
}
