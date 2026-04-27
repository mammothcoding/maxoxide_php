<?php

declare(strict_types=1);

namespace Maxoxide;

// ---------------------------------------------------------------------------
// String value sets
// ---------------------------------------------------------------------------

/** Chat type wire values. Unknown future values are preserved as strings. */
class ChatType
{
    public const DIALOG = 'dialog';
    public const CHAT = 'chat';
    public const CHANNEL = 'channel';
}

/** Chat status wire values. Unknown future values are preserved as strings. */
class ChatStatus
{
    public const ACTIVE = 'active';
    public const REMOVED = 'removed';
    public const LEFT = 'left';
    public const CLOSED = 'closed';
}

/** Message text format values. Omit format for plain text. */
class MessageFormat
{
    public const MARKDOWN = 'markdown';
    public const HTML = 'html';
}

/** Visual style values for inline buttons. */
class ButtonIntent
{
    public const DEFAULT = 'default';
    public const POSITIVE = 'positive';
    public const NEGATIVE = 'negative';
}

/** Link type values for outgoing message links. */
class LinkType
{
    public const FORWARD = 'forward';
    public const REPLY = 'reply';
}

/** Attachment kind values used by parsed messages and dispatcher filters. */
class AttachmentKind
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const AUDIO = 'audio';
    public const FILE = 'file';
    public const STICKER = 'sticker';
    public const INLINE_KEYBOARD = 'inline_keyboard';
    public const LOCATION = 'location';
    public const CONTACT = 'contact';
    public const UNKNOWN = 'unknown';
}

/** Chat administrator permission values. Unknown future values are preserved as strings. */
class ChatAdminPermission
{
    public const READ_ALL_MESSAGES = 'read_all_messages';
    public const ADD_REMOVE_MEMBERS = 'add_remove_members';
    public const ADD_ADMINS = 'add_admins';
    public const CHANGE_CHAT_INFO = 'change_chat_info';
    public const PIN_MESSAGE = 'pin_message';
    public const WRITE = 'write';
    public const CAN_CALL = 'can_call';
    public const EDIT_LINK = 'edit_link';
    public const POST_EDIT_DELETE_MESSAGE = 'post_edit_delete_message';
    public const EDIT_MESSAGE = 'edit_message';
    public const DELETE_MESSAGE = 'delete_message';
}

/** Sender action values for POST /chats/{chatId}/actions. */
class SenderAction
{
    public const TYPING_ON = 'typing_on';
    public const SENDING_IMAGE = 'sending_photo';
    public const SENDING_VIDEO = 'sending_video';
    public const SENDING_AUDIO = 'sending_audio';
    public const SENDING_FILE = 'sending_file';
    public const MARK_SEEN = 'mark_seen';
}

// ---------------------------------------------------------------------------
// User / Bot info
// ---------------------------------------------------------------------------

/**
 * Represents a Max user or bot.
 *
 * Do not confuse `userId` with `chatId`: one user can appear in different
 * dialogs/groups, each with its own `chatId`.
 */
class User
{
    /** Global MAX user identifier. */
    public int $userId;
    public string $firstName;
    public ?string $lastName = null;
    /** Legacy alias kept for PHP callers that still read `$user->name`. */
    public string $name;
    public ?string $username = null;
    public ?bool $isBot = null;
    public ?int $lastActivityTime = null;
    public ?string $description = null;
    public ?string $avatarUrl = null;
    public ?string $fullAvatarUrl = null;
    /** @var BotCommand[]|null */
    public ?array $commands = null;

    /**
     * @param string|null $lastName Optional MAX profile last name.
     */
    public function __construct(int $userId, string $firstName, ?string $lastName = null)
    {
        $this->userId = $userId;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->name = $this->displayName();
    }

    /** Parse a MAX user object, accepting both current first_name and legacy name fields. */
    public static function fromArray(array $d): self
    {
        $firstName = isset($d['first_name']) ? (string) $d['first_name'] : (string) ($d['name'] ?? '');
        $u = new self(
            (int) ($d['user_id'] ?? 0),
            $firstName,
            isset($d['last_name']) ? (string) $d['last_name'] : null
        );
        $u->name = isset($d['name']) ? (string) $d['name'] : $u->displayName();
        $u->username = isset($d['username']) ? (string) $d['username'] : null;
        $u->isBot = isset($d['is_bot']) ? (bool) $d['is_bot'] : null;
        $u->lastActivityTime = isset($d['last_activity_time']) ? (int) $d['last_activity_time'] : null;
        $u->description = isset($d['description']) ? (string) $d['description'] : null;
        $u->avatarUrl = isset($d['avatar_url']) ? (string) $d['avatar_url'] : null;
        $u->fullAvatarUrl = isset($d['full_avatar_url']) ? (string) $d['full_avatar_url'] : null;
        $u->commands = isset($d['commands']) && is_array($d['commands'])
            ? array_map(static fn(array $c) => BotCommand::fromArray($c), $d['commands'])
            : null;

        return $u;
    }

    /** Return a printable display name from first and last name. */
    public function displayName(): string
    {
        $displayName = $this->firstName;
        if ($this->lastName !== null && $this->lastName !== '') {
            $displayName .= ' ' . $this->lastName;
        }

        return $displayName;
    }
}

// ---------------------------------------------------------------------------
// Chat
// ---------------------------------------------------------------------------

/** Small image object used by chat icons and media metadata. */
class Image
{
    public string $url;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    /** Parse an image object from a MAX response. */
    public static function fromArray(array $d): self
    {
        return new self((string) ($d['url'] ?? ''));
    }
}

/**
 * Represents a Max chat (dialog, group, or channel).
 *
 * `chatId` identifies the concrete dialog/group, not the user.
 */
class Chat
{
    public int $chatId;
    public string $type;
    public ?string $status = null;
    public ?string $title = null;
    public ?Image $icon = null;
    /** Legacy convenience alias for `$chat->icon?->url`. */
    public ?string $iconUrl = null;
    public ?int $lastEventTime = null;
    public ?int $participantsCount = null;
    public ?int $ownerId = null;
    public ?bool $isPublic = null;
    public ?string $link = null;
    public ?string $description = null;
    public ?User $dialogWithUser = null;
    public ?string $chatMessageId = null;
    public ?Message $pinnedMessage = null;

    public function __construct(int $chatId, string $type)
    {
        $this->chatId = $chatId;
        $this->type = $type;
    }

    /** Parse a chat object from a MAX response. */
    public static function fromArray(array $d): self
    {
        $c = new self((int) ($d['chat_id'] ?? 0), (string) ($d['type'] ?? ChatType::CHAT));
        $c->status = isset($d['status']) ? (string) $d['status'] : null;
        $c->title = isset($d['title']) ? (string) $d['title'] : null;
        $c->icon = isset($d['icon']) && is_array($d['icon']) ? Image::fromArray($d['icon']) : null;
        $c->iconUrl = $c->icon !== null ? $c->icon->url : null;
        $c->lastEventTime = isset($d['last_event_time']) ? (int) $d['last_event_time'] : null;
        $c->participantsCount = isset($d['participants_count']) ? (int) $d['participants_count'] : null;
        $c->ownerId = isset($d['owner_id']) ? (int) $d['owner_id'] : null;
        $c->isPublic = isset($d['is_public']) ? (bool) $d['is_public'] : null;
        $c->link = isset($d['link']) ? (string) $d['link'] : null;
        $c->description = isset($d['description']) ? (string) $d['description'] : null;
        $c->dialogWithUser = isset($d['dialog_with_user']) && is_array($d['dialog_with_user'])
            ? User::fromArray($d['dialog_with_user'])
            : null;
        $c->chatMessageId = isset($d['chat_message_id']) ? (string) $d['chat_message_id'] : null;
        $c->pinnedMessage = isset($d['pinned_message']) && is_array($d['pinned_message'])
            ? Message::fromArray($d['pinned_message'])
            : null;

        return $c;
    }
}

/** Response from GET /chats. */
class ChatList
{
    /** @var Chat[] */
    public array $chats;
    public ?int $marker;

    /** @param Chat[] $chats */
    public function __construct(array $chats, ?int $marker = null)
    {
        $this->chats = $chats;
        $this->marker = $marker;
    }

    /** Parse a chat list response. */
    public static function fromArray(array $d): self
    {
        return new self(
            array_map(static fn(array $c) => Chat::fromArray($c), $d['chats'] ?? []),
            isset($d['marker']) ? (int) $d['marker'] : null
        );
    }
}

/** Body for PATCH /chats/{chatId}. */
class EditChatBody
{
    public ?PhotoAttachmentPayload $icon = null;
    public ?string $title = null;
    public ?string $description = null;
    public ?string $pin = null;
    public ?bool $notify = null;

    /** Serialize this body for the MAX API. */
    public function toArray(): array
    {
        return array_filter([
            'icon' => $this->icon !== null ? $this->icon->toArray() : null,
            'title' => $this->title,
            'description' => $this->description,
            'pin' => $this->pin,
            'notify' => $this->notify,
        ], static fn($v) => $v !== null);
    }
}

// ---------------------------------------------------------------------------
// Chat members and admins
// ---------------------------------------------------------------------------

/** Chat member info from GET /chats/{id}/members. */
class ChatMember
{
    public int $userId;
    public string $firstName;
    public ?string $lastName = null;
    /** Legacy alias kept for PHP callers that still read `$member->name`. */
    public string $name;
    public ?string $username = null;
    public ?string $avatarUrl = null;
    public ?string $fullAvatarUrl = null;
    public ?string $description = null;
    public ?bool $isOwner = null;
    public ?bool $isAdmin = null;
    public ?int $joinTime = null;
    /** @var string[]|null */
    public ?array $permissions = null;
    public ?int $lastActivityTime = null;
    public ?int $lastAccessTime = null;
    public ?bool $isBot = null;
    public ?string $alias = null;

    public function __construct(int $userId, string $firstName, ?string $lastName = null)
    {
        $this->userId = $userId;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->name = $this->displayName();
    }

    /** Parse a chat member, accepting current first_name and legacy name fields. */
    public static function fromArray(array $d): self
    {
        $firstName = isset($d['first_name']) ? (string) $d['first_name'] : (string) ($d['name'] ?? '');
        $m = new self(
            (int) ($d['user_id'] ?? 0),
            $firstName,
            isset($d['last_name']) ? (string) $d['last_name'] : null
        );
        $m->name = isset($d['name']) ? (string) $d['name'] : $m->displayName();
        $m->username = isset($d['username']) ? (string) $d['username'] : null;
        $m->avatarUrl = isset($d['avatar_url']) ? (string) $d['avatar_url'] : null;
        $m->fullAvatarUrl = isset($d['full_avatar_url']) ? (string) $d['full_avatar_url'] : null;
        $m->description = isset($d['description']) ? (string) $d['description'] : null;
        $m->isOwner = isset($d['is_owner']) ? (bool) $d['is_owner'] : null;
        $m->isAdmin = isset($d['is_admin']) ? (bool) $d['is_admin'] : null;
        $m->joinTime = isset($d['join_time']) ? (int) $d['join_time'] : null;
        $m->permissions = isset($d['permissions']) ? array_values((array) $d['permissions']) : null;
        $m->lastActivityTime = isset($d['last_activity_time']) ? (int) $d['last_activity_time'] : null;
        $m->lastAccessTime = isset($d['last_access_time']) ? (int) $d['last_access_time'] : null;
        $m->isBot = isset($d['is_bot']) ? (bool) $d['is_bot'] : null;
        $m->alias = isset($d['alias']) ? (string) $d['alias'] : null;

        return $m;
    }

    /** Return a printable display name from first and last name. */
    public function displayName(): string
    {
        $displayName = $this->firstName;
        if ($this->lastName !== null && $this->lastName !== '') {
            $displayName .= ' ' . $this->lastName;
        }

        return $displayName;
    }
}

/** Response from GET /chats/{id}/members. */
class ChatMembersList
{
    /** @var ChatMember[] */
    public array $members;
    public ?int $marker;

    /** @param ChatMember[] $members */
    public function __construct(array $members, ?int $marker = null)
    {
        $this->members = $members;
        $this->marker = $marker;
    }

    /** Parse a chat members list response. */
    public static function fromArray(array $d): self
    {
        return new self(
            array_map(static fn(array $m) => ChatMember::fromArray($m), $d['members'] ?? []),
            isset($d['marker']) ? (int) $d['marker'] : null
        );
    }
}

/** Administrator rights entry for POST /chats/{chatId}/members/admins. */
class ChatAdmin
{
    public int $userId;
    /** @var string[] */
    public array $permissions;
    public ?string $alias;

    /** @param string[] $permissions */
    public function __construct(int $userId, array $permissions, ?string $alias = null)
    {
        $this->userId = $userId;
        $this->permissions = array_values($permissions);
        $this->alias = $alias;
    }

    /** Serialize this admin entry for the MAX API. */
    public function toArray(): array
    {
        $d = [
            'user_id' => $this->userId,
            'permissions' => $this->permissions,
        ];
        if ($this->alias !== null) {
            $d['alias'] = $this->alias;
        }

        return $d;
    }
}

// ---------------------------------------------------------------------------
// Message
// ---------------------------------------------------------------------------

/**
 * Target chat metadata attached to a received message.
 *
 * In private dialogs `chatId` is the dialog ID; `userId` is the peer's global ID.
 */
class Recipient
{
    public int $chatId;
    public string $chatType;
    public ?int $userId;

    public function __construct(int $chatId, string $chatType, ?int $userId = null)
    {
        $this->chatId = $chatId;
        $this->chatType = $chatType;
        $this->userId = $userId;
    }

    /** Parse recipient metadata from a MAX message. */
    public static function fromArray(array $d): self
    {
        return new self(
            (int) ($d['chat_id'] ?? 0),
            (string) ($d['chat_type'] ?? ChatType::CHAT),
            isset($d['user_id']) ? (int) $d['user_id'] : null
        );
    }
}

/**
 * An attachment inside a received message.
 *
 * MAX may send either `{"type": "...", "payload": {...}}` or a flat object
 * with typed fields directly on the attachment; both forms are accepted.
 */
class Attachment
{
    public string $type;
    /** @var array<string, mixed>|null */
    public ?array $payload = null;

    public ?string $url = null;
    public ?string $token = null;
    public ?int $photoId = null;
    public ?string $filename = null;
    public ?int $fileSize = null;
    public ?string $stickerCode = null;
    public ?int $width = null;
    public ?int $height = null;
    /** @var array<int, Button[]> */
    public array $buttons = [];
    public ?float $latitude = null;
    public ?float $longitude = null;
    public ?string $contactName = null;
    public ?int $contactId = null;
    public ?string $vcfInfo = null;
    public ?string $vcfPhone = null;
    /** @var array<string, mixed> */
    public array $raw = [];

    public function __construct(string $type)
    {
        $this->type = $type;
    }

    /** Parse a message attachment with forward-compatible unknown fallback. */
    public static function fromArray(array $d): self
    {
        $type = (string) ($d['type'] ?? AttachmentKind::UNKNOWN);
        $a = new self($type);
        $payload = isset($d['payload']) && is_array($d['payload']) ? $d['payload'] : $d;
        $a->payload = $payload;

        switch ($type) {
            case AttachmentKind::IMAGE:
            case AttachmentKind::VIDEO:
            case AttachmentKind::AUDIO:
                $a->url = isset($payload['url']) ? (string) $payload['url'] : null;
                $a->token = isset($payload['token']) ? (string) $payload['token'] : null;
                $a->photoId = isset($payload['photo_id']) ? (int) $payload['photo_id'] : null;
                break;
            case AttachmentKind::FILE:
                $a->url = isset($payload['url']) ? (string) $payload['url'] : null;
                $a->token = isset($payload['token']) ? (string) $payload['token'] : null;
                $a->filename = isset($payload['filename']) ? (string) $payload['filename'] : null;
                $a->fileSize = isset($payload['size']) ? (int) $payload['size'] : null;
                break;
            case AttachmentKind::STICKER:
                $a->stickerCode = (string) ($payload['code'] ?? '');
                $a->url = isset($payload['url']) ? (string) $payload['url'] : null;
                $a->width = isset($payload['width']) ? (int) $payload['width'] : null;
                $a->height = isset($payload['height']) ? (int) $payload['height'] : null;
                break;
            case AttachmentKind::INLINE_KEYBOARD:
                foreach ((array) ($payload['buttons'] ?? []) as $row) {
                    $a->buttons[] = array_map(
                        static fn(array $b) => Button::fromArray($b),
                        (array) $row
                    );
                }
                break;
            case AttachmentKind::LOCATION:
                $a->latitude = isset($payload['latitude']) ? (float) $payload['latitude'] : null;
                $a->longitude = isset($payload['longitude']) ? (float) $payload['longitude'] : null;
                break;
            case AttachmentKind::CONTACT:
                $a->contactName = isset($payload['name']) ? (string) $payload['name'] : null;
                $a->contactId = isset($payload['contact_id']) ? (int) $payload['contact_id'] : null;
                $a->vcfInfo = isset($payload['vcf_info']) ? (string) $payload['vcf_info'] : null;
                $a->vcfPhone = isset($payload['vcf_phone']) ? (string) $payload['vcf_phone'] : null;
                break;
            default:
                $a->raw = $d;
        }

        return $a;
    }

    /** Return this attachment's dispatcher kind. */
    public function kind(): string
    {
        $known = [
            AttachmentKind::IMAGE,
            AttachmentKind::VIDEO,
            AttachmentKind::AUDIO,
            AttachmentKind::FILE,
            AttachmentKind::STICKER,
            AttachmentKind::INLINE_KEYBOARD,
            AttachmentKind::LOCATION,
            AttachmentKind::CONTACT,
        ];
        $kind = in_array($this->type, $known, true) ? $this->type : AttachmentKind::UNKNOWN;

        return $kind;
    }
}

/** Body of a received message. */
class MessageBody
{
    public string $mid;
    public int $seq;
    public ?string $text;
    /** @var Attachment[] */
    public array $attachments;

    /** @param Attachment[] $attachments */
    public function __construct(string $mid, int $seq, ?string $text = null, array $attachments = [])
    {
        $this->mid = $mid;
        $this->seq = $seq;
        $this->text = $text;
        $this->attachments = $attachments;
    }

    /** Parse a message body, preserving malformed attachments as unknown where possible. */
    public static function fromArray(array $d): self
    {
        $atts = [];
        foreach ((array) ($d['attachments'] ?? []) as $raw) {
            if (is_array($raw)) {
                try {
                    $atts[] = Attachment::fromArray($raw);
                } catch (\Throwable $e) {
                    $unknown = new Attachment(AttachmentKind::UNKNOWN);
                    $unknown->raw = $raw;
                    $atts[] = $unknown;
                }
            }
        }

        return new self(
            (string) ($d['mid'] ?? ''),
            (int) ($d['seq'] ?? 0),
            isset($d['text']) ? (string) $d['text'] : null,
            $atts
        );
    }
}

/** View counters and other message statistics. */
class MessageStat
{
    public ?int $views = null;

    /** Parse message statistics from a MAX response. */
    public static function fromArray(array $d): self
    {
        $stat = new self();
        $stat->views = isset($d['views']) ? (int) $d['views'] : null;

        return $stat;
    }
}

/** Linked message metadata for reply/forward messages. */
class LinkedMessage
{
    public string $type;
    public ?User $sender = null;
    public ?int $chatId = null;
    public ?MessageBody $message = null;

    public function __construct(string $type)
    {
        $this->type = $type;
    }

    /** Parse linked message metadata. */
    public static function fromArray(array $d): self
    {
        $link = new self((string) ($d['type'] ?? ''));
        $link->sender = isset($d['sender']) && is_array($d['sender']) ? User::fromArray($d['sender']) : null;
        $link->chatId = isset($d['chat_id']) ? (int) $d['chat_id'] : null;
        $link->message = isset($d['message']) && is_array($d['message'])
            ? MessageBody::fromArray($d['message'])
            : null;

        return $link;
    }
}

/** Represents a received message. */
class Message
{
    public ?User $sender;
    public Recipient $recipient;
    public int $timestamp;
    public ?LinkedMessage $link = null;
    public MessageBody $body;
    public ?MessageStat $stat = null;
    /** Legacy convenience alias for `$message->stat?->views`. */
    public ?int $statViews = null;
    public ?string $url = null;
    /** @var array<string, mixed>|null */
    public ?array $constructor = null;

    public function __construct(Recipient $recipient, int $timestamp, MessageBody $body, ?User $sender = null)
    {
        $this->recipient = $recipient;
        $this->timestamp = $timestamp;
        $this->body = $body;
        $this->sender = $sender;
    }

    /** Return the dialog/group/channel chat_id for this message. */
    public function chatId(): int
    {
        return $this->recipient->chatId;
    }

    /** Return this message's MAX message ID. */
    public function messageId(): string
    {
        return $this->body->mid;
    }

    /** Return this message's text, if present. */
    public function text(): ?string
    {
        return $this->body->text;
    }

    /** Return the sender's global MAX user ID, if present. */
    public function senderUserId(): ?int
    {
        $senderUserId = null;
        if ($this->sender !== null) {
            $senderUserId = $this->sender->userId;
        }

        return $senderUserId;
    }

    /** Return true when this message contains at least one attachment. */
    public function hasAttachments(): bool
    {
        return $this->body->attachments !== [];
    }

    /** Parse a message object from a MAX response. */
    public static function fromArray(array $d): self
    {
        $msg = new self(
            Recipient::fromArray((array) ($d['recipient'] ?? [])),
            (int) ($d['timestamp'] ?? 0),
            MessageBody::fromArray((array) ($d['body'] ?? [])),
            isset($d['sender']) && is_array($d['sender']) ? User::fromArray($d['sender']) : null
        );
        $msg->link = isset($d['link']) && is_array($d['link']) ? LinkedMessage::fromArray($d['link']) : null;
        $msg->stat = isset($d['stat']) && is_array($d['stat']) ? MessageStat::fromArray($d['stat']) : null;
        $msg->statViews = $msg->stat !== null ? $msg->stat->views : null;
        $msg->url = isset($d['url']) ? (string) $d['url'] : null;
        $msg->constructor = isset($d['constructor']) && is_array($d['constructor']) ? $d['constructor'] : null;

        return $msg;
    }
}

/** Response from GET /messages. */
class MessageList
{
    /** @var Message[] */
    public array $messages;

    /** @param Message[] $messages */
    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    /** Parse a message list response. */
    public static function fromArray(array $d): self
    {
        return new self(
            array_map(static fn(array $m) => Message::fromArray($m), $d['messages'] ?? [])
        );
    }
}

// ---------------------------------------------------------------------------
// Keyboard / Buttons
// ---------------------------------------------------------------------------

/**
 * An inline keyboard button.
 *
 * Factory methods cover callback, link, message, open_app, clipboard,
 * request_contact, and request_geo_location buttons.
 */
class Button
{
    public string $type;
    public string $text;
    public ?string $payload = null;
    public ?string $url = null;
    public ?string $intent = null;
    public ?bool $quick = null;
    public ?string $webApp = null;
    public ?int $contactId = null;

    public function __construct(string $type, string $text)
    {
        $this->type = $type;
        $this->text = $text;
    }

    /** Create a callback button. */
    public static function callback(string $text, string $payload, ?string $intent = null): self
    {
        $b = new self('callback', $text);
        $b->payload = $payload;
        $b->intent = $intent;

        return $b;
    }

    /** Create a link button. */
    public static function link(string $text, string $url, ?string $intent = null): self
    {
        $b = new self('link', $text);
        $b->url = $url;
        $b->intent = $intent;

        return $b;
    }

    /** Create a button that sends its text as a user message. */
    public static function message(string $text, ?string $intent = null): self
    {
        $b = new self('message', $text);
        $b->intent = $intent;

        return $b;
    }

    /** Create an open_app button with an official MAX web_app field. */
    public static function openApp(string $text, string $webApp): self
    {
        return self::openAppFull($text, $webApp);
    }

    /** Create an open_app button with a payload. */
    public static function openAppWithPayload(string $text, string $webApp, string $payload): self
    {
        return self::openAppFull($text, $webApp, $payload);
    }

    /** Create an open_app button with all supported optional fields. */
    public static function openAppFull(
        string $text,
        string $webApp,
        ?string $payload = null,
        ?int $contactId = null
    ): self {
        $b = new self('open_app', $text);
        $b->webApp = $webApp;
        $b->payload = $payload;
        $b->contactId = $contactId;

        return $b;
    }

    /** Create a clipboard button. */
    public static function clipboard(string $text, string $payload): self
    {
        $b = new self('clipboard', $text);
        $b->payload = $payload;

        return $b;
    }

    /** Create a button that requests the user's contact card. */
    public static function requestContact(string $text): self
    {
        return new self('request_contact', $text);
    }

    /** Create a button that requests the user's geo location. */
    public static function requestGeoLocation(string $text, ?bool $quick = null): self
    {
        $b = new self('request_geo_location', $text);
        $b->quick = $quick;

        return $b;
    }

    /** Serialize this button for the MAX API. */
    public function toArray(): array
    {
        $d = ['type' => $this->type, 'text' => $this->text];
        if ($this->payload !== null) {
            $d['payload'] = $this->payload;
        }
        if ($this->url !== null) {
            $d['url'] = $this->url;
        }
        if ($this->intent !== null) {
            $d['intent'] = $this->intent;
        }
        if ($this->quick !== null) {
            $d['quick'] = $this->quick;
        }
        if ($this->webApp !== null && $this->webApp !== '') {
            $d['web_app'] = $this->webApp;
        }
        if ($this->contactId !== null) {
            $d['contact_id'] = $this->contactId;
        }

        return $d;
    }

    /** Parse a button from a MAX response. */
    public static function fromArray(array $d): self
    {
        $b = new self((string) ($d['type'] ?? 'callback'), (string) ($d['text'] ?? ''));
        $b->payload = isset($d['payload']) ? (string) $d['payload'] : null;
        $b->url = isset($d['url']) ? (string) $d['url'] : null;
        $b->intent = isset($d['intent']) ? (string) $d['intent'] : null;
        $b->quick = isset($d['quick']) ? (bool) $d['quick'] : null;
        $b->webApp = isset($d['web_app']) ? (string) $d['web_app'] : null;
        $b->contactId = isset($d['contact_id']) ? (int) $d['contact_id'] : null;

        return $b;
    }
}

/** An inline keyboard grid. Max allows up to 30 rows and 7 buttons per row. */
class KeyboardPayload
{
    /** @var array<int, Button[]> */
    public array $buttons;

    /** @param array<int, Button[]> $buttons */
    public function __construct(array $buttons = [])
    {
        $this->buttons = $buttons;
    }

    /** Serialize this keyboard for the MAX API. */
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

// ---------------------------------------------------------------------------
// Outgoing message
// ---------------------------------------------------------------------------

/** One photo token in an image upload `photos` map. */
class PhotoToken
{
    public string $token;
    /** @var array<string, mixed> */
    public array $extra;

    /** @param array<string, mixed> $extra */
    public function __construct(string $token, array $extra = [])
    {
        $this->token = $token;
        $this->extra = $extra;
    }

    /** Parse a photo token from an upload response. */
    public static function fromArray(array $d): self
    {
        $extra = $d;
        unset($extra['token']);

        return new self((string) ($d['token'] ?? ''), $extra);
    }

    /** Serialize this photo token. */
    public function toArray(): array
    {
        return array_merge(['token' => $this->token], $this->extra);
    }
}

/** Payload for image attachments; supports URL, simple token, or MAX photos map. */
class ImageAttachmentPayload
{
    public ?string $url = null;
    public ?string $token = null;
    /** @var array<string, PhotoToken>|null */
    public ?array $photos = null;

    /** Create an image payload from a simple upload token. */
    public static function token(string $token): self
    {
        $p = new self();
        $p->token = $token;

        return $p;
    }

    /** Create an image payload from a remote image URL. */
    public static function url(string $url): self
    {
        $p = new self();
        $p->url = $url;

        return $p;
    }

    /**
     * Create an image payload from the upload response `photos` map.
     *
     * @param array<string, PhotoToken|array<string, mixed>> $photos
     */
    public static function photos(array $photos): self
    {
        $p = new self();
        $p->photos = [];
        foreach ($photos as $key => $photo) {
            $p->photos[(string) $key] = $photo instanceof PhotoToken ? $photo : PhotoToken::fromArray((array) $photo);
        }

        return $p;
    }

    /** Serialize this image payload for the MAX API. */
    public function toArray(): array
    {
        $d = [];
        if ($this->url !== null) {
            $d['url'] = $this->url;
        }
        if ($this->token !== null) {
            $d['token'] = $this->token;
        }
        if ($this->photos !== null) {
            $d['photos'] = [];
            foreach ($this->photos as $key => $photo) {
                $d['photos'][$key] = $photo->toArray();
            }
        }

        return $d;
    }
}

/** Payload for uploaded video, audio, and file attachments. */
class UploadedToken
{
    public string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /** Serialize this uploaded token payload. */
    public function toArray(): array
    {
        return ['token' => $this->token];
    }
}

/** Attachment to include in an outgoing message. */
class NewAttachment
{
    public string $type;
    /** @var array<string, mixed> */
    public array $payload;

    /** @param array<string, mixed> $payload */
    private function __construct(string $type, array $payload)
    {
        $this->type = $type;
        $this->payload = $payload;
    }

    /** Create an inline keyboard attachment. */
    public static function inlineKeyboard(KeyboardPayload $keyboard): self
    {
        return new self(AttachmentKind::INLINE_KEYBOARD, $keyboard->toArray());
    }

    /** Create an image attachment from a simple token. */
    public static function image(string $token): self
    {
        return self::imagePayload(ImageAttachmentPayload::token($token));
    }

    /** Create an image attachment from a remote URL. */
    public static function imageUrl(string $url): self
    {
        return self::imagePayload(ImageAttachmentPayload::url($url));
    }

    /**
     * Create an image attachment from MAX upload `photos`.
     *
     * @param array<string, PhotoToken|array<string, mixed>> $photos
     */
    public static function imagePhotos(array $photos): self
    {
        return self::imagePayload(ImageAttachmentPayload::photos($photos));
    }

    /** Create an image attachment from a prepared image payload. */
    public static function imagePayload(ImageAttachmentPayload $payload): self
    {
        return new self(AttachmentKind::IMAGE, $payload->toArray());
    }

    /** Create a video attachment from an upload token. */
    public static function video(string $token): self
    {
        return new self(AttachmentKind::VIDEO, (new UploadedToken($token))->toArray());
    }

    /** Create an audio attachment from an upload token. */
    public static function audio(string $token): self
    {
        return new self(AttachmentKind::AUDIO, (new UploadedToken($token))->toArray());
    }

    /** Create a file attachment from an upload token. */
    public static function file(string $token): self
    {
        return new self(AttachmentKind::FILE, (new UploadedToken($token))->toArray());
    }

    /** Serialize this outgoing attachment for the MAX API. */
    public function toArray(): array
    {
        return ['type' => $this->type, 'payload' => $this->payload];
    }
}

/** Link metadata for outgoing reply/forward messages. */
class NewMessageLink
{
    public string $type;
    public string $mid;

    public function __construct(string $type, string $mid)
    {
        $this->type = $type;
        $this->mid = $mid;
    }

    /** Serialize this link for the MAX API. */
    public function toArray(): array
    {
        return ['type' => $this->type, 'mid' => $this->mid];
    }
}

/** Body for POST /messages. */
class NewMessageBody
{
    public ?string $text = null;
    /** @var NewAttachment[]|null */
    public ?array $attachments = null;
    public ?NewMessageLink $link = null;
    public ?bool $notify = null;
    public ?string $format = null;

    /** Create an empty outgoing message body. */
    public static function empty(): self
    {
        return new self();
    }

    /** Create a text outgoing message body. */
    public static function text(string $text): self
    {
        $b = new self();
        $b->text = $text;

        return $b;
    }

    /** Create a text body when text is non-null, otherwise an empty body. */
    public static function textOpt(?string $text): self
    {
        $body = $text !== null ? self::text($text) : self::empty();

        return $body;
    }

    /** Set text format: `markdown` or `html`; omit format for plain text. */
    public function withFormat(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /** Attach an inline keyboard to this body. */
    public function withKeyboard(KeyboardPayload $keyboard): self
    {
        return $this->withAttachment(NewAttachment::inlineKeyboard($keyboard));
    }

    /** Attach a single outgoing attachment to this body. */
    public function withAttachment(NewAttachment $att): self
    {
        $this->attachments = $this->attachments ?? [];
        $this->attachments[] = $att;

        return $this;
    }

    /**
     * Attach multiple outgoing attachments to this body.
     *
     * @param NewAttachment[] $attachments
     */
    public function withAttachments(array $attachments): self
    {
        foreach ($attachments as $attachment) {
            $this->withAttachment($attachment);
        }

        return $this;
    }

    /** Link to another message: $type = `reply` or `forward`. */
    public function withLink(string $type, string $mid): self
    {
        $this->link = new NewMessageLink($type, $mid);

        return $this;
    }

    /** Mark this outgoing message as a reply to an existing message. */
    public function withReplyTo(string $messageId): self
    {
        return $this->withLink(LinkType::REPLY, $messageId);
    }

    /** Mark this outgoing message as forwarded from an existing message. */
    public function withForwardFrom(string $messageId): self
    {
        return $this->withLink(LinkType::FORWARD, $messageId);
    }

    /** Set whether MAX should notify recipients about this message. */
    public function withNotify(bool $notify): self
    {
        $this->notify = $notify;

        return $this;
    }

    /** Serialize this body for the MAX API. */
    public function toArray(): array
    {
        $d = [];
        if ($this->text !== null) {
            $d['text'] = $this->text;
        }
        if ($this->attachments !== null) {
            $d['attachments'] = array_map(static fn(NewAttachment $a) => $a->toArray(), $this->attachments);
        }
        if ($this->link !== null) {
            $d['link'] = $this->link->toArray();
        }
        if ($this->notify !== null) {
            $d['notify'] = $this->notify;
        }
        if ($this->format !== null) {
            $d['format'] = $this->format;
        }

        return $d;
    }
}

/** Query options for POST /messages. */
class SendMessageOptions
{
    public ?bool $disableLinkPreview = null;

    /** Create options with disable_link_preview set. */
    public static function disableLinkPreview(bool $disable): self
    {
        $options = new self();
        $options->disableLinkPreview = $disable;

        return $options;
    }

    /** Serialize this option set as query parameters. */
    public function toQueryArray(): array
    {
        $q = [];
        if ($this->disableLinkPreview !== null) {
            $q['disable_link_preview'] = $this->disableLinkPreview ? 'true' : 'false';
        }

        return $q;
    }
}

/** Body for POST /answers. */
class AnswerCallbackBody
{
    public string $callbackId;
    public ?NewMessageBody $message = null;
    public ?string $notification = null;

    public function __construct(string $callbackId)
    {
        $this->callbackId = $callbackId;
    }

    /** Serialize this body for the MAX API. */
    public function toArray(): array
    {
        $d = [];
        if ($this->message !== null) {
            $d['message'] = $this->message->toArray();
        }
        if ($this->notification !== null) {
            $d['notification'] = $this->notification;
        }

        return $d;
    }
}

// ---------------------------------------------------------------------------
// Misc API responses
// ---------------------------------------------------------------------------

/** Generic simple JSON result. */
class SimpleResult
{
    public bool $success;
    public ?string $message;
    /** @var int[]|null */
    public ?array $failedUserIds = null;
    /** @var array<int, mixed>|null */
    public ?array $failedUserDetails = null;

    public function __construct(bool $success, ?string $message = null)
    {
        $this->success = $success;
        $this->message = $message;
    }

    /** Parse a simple result response. */
    public static function fromArray(array $d): self
    {
        $r = new self((bool) ($d['success'] ?? false), isset($d['message']) ? (string) $d['message'] : null);
        $r->failedUserIds = isset($d['failed_user_ids']) ? array_map('intval', (array) $d['failed_user_ids']) : null;
        $r->failedUserDetails = isset($d['failed_user_details']) ? (array) $d['failed_user_details'] : null;

        return $r;
    }
}

/** Pinned message info. */
class PinnedMessage
{
    public Message $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    /** Parse a pinned message response. */
    public static function fromArray(array $d): self
    {
        return new self(Message::fromArray((array) ($d['message'] ?? [])));
    }
}

/** Body for PUT /chats/{chatId}/pin. */
class PinMessageBody
{
    public string $messageId;
    public ?bool $notify = null;

    public function __construct(string $messageId, ?bool $notify = null)
    {
        $this->messageId = $messageId;
        $this->notify = $notify;
    }

    /** Serialize this body for the MAX API. */
    public function toArray(): array
    {
        $d = ['message_id' => $this->messageId];
        if ($this->notify !== null) {
            $d['notify'] = $this->notify;
        }

        return $d;
    }
}

/** Bot command entry exposed by GET /me and used by setMyCommands. */
class BotCommand
{
    public string $name;
    public string $description;

    public function __construct(string $name, string $description)
    {
        $this->name = $name;
        $this->description = $description;
    }

    /** Parse a bot command object. */
    public static function fromArray(array $d): self
    {
        return new self((string) ($d['name'] ?? ''), (string) ($d['description'] ?? ''));
    }

    /** Serialize this command for the MAX API. */
    public function toArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description];
    }
}

/** Webhook subscription object. */
class Subscription
{
    public string $url;
    public int $time;
    /** @var string[]|null */
    public ?array $updateTypes = null;
    public ?string $version = null;

    public function __construct(string $url, int $time)
    {
        $this->url = $url;
        $this->time = $time;
    }

    /** Parse a webhook subscription object. */
    public static function fromArray(array $d): self
    {
        $s = new self((string) ($d['url'] ?? ''), (int) ($d['time'] ?? 0));
        $s->updateTypes = isset($d['update_types']) ? array_values((array) $d['update_types']) : null;
        $s->version = isset($d['version']) ? (string) $d['version'] : null;

        return $s;
    }
}

/** Response from GET /subscriptions. */
class SubscriptionList
{
    /** @var Subscription[] */
    public array $subscriptions;

    /** @param Subscription[] $subscriptions */
    public function __construct(array $subscriptions)
    {
        $this->subscriptions = $subscriptions;
    }

    /** Parse a subscription list response. */
    public static function fromArray(array $d): self
    {
        return new self(
            array_map(static fn(array $s) => Subscription::fromArray($s), $d['subscriptions'] ?? [])
        );
    }
}

/** Body for POST /subscriptions. */
class SubscribeBody
{
    /** HTTPS URL of your endpoint. */
    public string $url;
    /** @var string[]|null Optional list of update types to receive. */
    public ?array $updateTypes = null;
    public ?string $version = null;
    /** Optional shared secret sent in the X-Max-Bot-Api-Secret header. */
    public ?string $secret = null;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    /** Serialize this body for the MAX API. */
    public function toArray(): array
    {
        $d = ['url' => $this->url];
        if ($this->updateTypes !== null) {
            $d['update_types'] = $this->updateTypes;
        }
        if ($this->version !== null) {
            $d['version'] = $this->version;
        }
        if ($this->secret !== null) {
            $d['secret'] = $this->secret;
        }

        return $d;
    }
}

/** Response from the upload endpoint's multipart upload body. */
class UploadResponse
{
    public ?string $token = null;
    /** @var array<string, PhotoToken>|null */
    public ?array $photos = null;

    /** Parse an upload response body. */
    public static function fromArray(array $d): self
    {
        $r = new self();
        $r->token = isset($d['token']) ? (string) $d['token'] : null;
        if (isset($d['photos']) && is_array($d['photos'])) {
            $r->photos = [];
            foreach ($d['photos'] as $key => $photo) {
                if (is_array($photo)) {
                    $r->photos[(string) $key] = PhotoToken::fromArray($photo);
                }
            }
        }

        return $r;
    }
}

/** Upload type for POST /uploads. */
class UploadType
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const AUDIO = 'audio';
    public const FILE = 'file';
}

/** Response from POST /uploads. */
class UploadEndpoint
{
    public string $url;
    public ?string $token;

    public function __construct(string $url, ?string $token = null)
    {
        $this->url = $url;
        $this->token = $token;
    }

    /** Parse an upload endpoint response. */
    public static function fromArray(array $d): self
    {
        return new self((string) ($d['url'] ?? ''), isset($d['token']) ? (string) $d['token'] : null);
    }
}

/** Arbitrary video URL metadata returned by GET /videos/{videoToken}. */
class VideoUrls
{
    /** @var array<string, mixed> */
    public array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /** Parse video URLs from a MAX response. */
    public static function fromArray(array $d): self
    {
        return new self($d);
    }
}

/** Photo payload used by video thumbnails and chat edit icon. */
class PhotoAttachmentPayload
{
    public ?string $url = null;
    public ?string $token = null;
    public ?int $photoId = null;
    public ?int $width = null;
    public ?int $height = null;
    /** @var array<string, mixed> */
    public array $extra = [];

    /** Parse a photo attachment payload. */
    public static function fromArray(array $d): self
    {
        $p = new self();
        $p->url = isset($d['url']) ? (string) $d['url'] : null;
        $p->token = isset($d['token']) ? (string) $d['token'] : null;
        $p->photoId = isset($d['photo_id']) ? (int) $d['photo_id'] : null;
        $p->width = isset($d['width']) ? (int) $d['width'] : null;
        $p->height = isset($d['height']) ? (int) $d['height'] : null;
        $p->extra = $d;
        unset($p->extra['url'], $p->extra['token'], $p->extra['photo_id'], $p->extra['width'], $p->extra['height']);

        return $p;
    }

    /** Serialize this photo payload for the MAX API. */
    public function toArray(): array
    {
        $d = $this->extra;
        if ($this->url !== null) {
            $d['url'] = $this->url;
        }
        if ($this->token !== null) {
            $d['token'] = $this->token;
        }
        if ($this->photoId !== null) {
            $d['photo_id'] = $this->photoId;
        }
        if ($this->width !== null) {
            $d['width'] = $this->width;
        }
        if ($this->height !== null) {
            $d['height'] = $this->height;
        }

        return $d;
    }
}

/** Video metadata returned by GET /videos/{videoToken}. */
class VideoInfo
{
    public string $token;
    public ?VideoUrls $urls = null;
    public ?PhotoAttachmentPayload $thumbnail = null;
    public ?int $width = null;
    public ?int $height = null;
    public ?int $duration = null;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /** Parse video metadata from a MAX response. */
    public static function fromArray(array $d): self
    {
        $v = new self((string) ($d['token'] ?? ''));
        $v->urls = isset($d['urls']) && is_array($d['urls']) ? VideoUrls::fromArray($d['urls']) : null;
        $v->thumbnail = isset($d['thumbnail']) && is_array($d['thumbnail'])
            ? PhotoAttachmentPayload::fromArray($d['thumbnail'])
            : null;
        $v->width = isset($d['width']) ? (int) $d['width'] : null;
        $v->height = isset($d['height']) ? (int) $d['height'] : null;
        $v->duration = isset($d['duration']) ? (int) $d['duration'] : null;

        return $v;
    }
}
