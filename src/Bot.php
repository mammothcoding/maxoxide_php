<?php

declare(strict_types=1);

namespace Maxoxide;

/**
 * The main entry point for the Max Bot API.
 *
 * Uses PHP's built-in cURL extension — no third-party HTTP client needed.
 *
 * Usage:
 *   $bot = new Bot('your_token');
 *   $bot = Bot::fromEnv();          // reads MAX_BOT_TOKEN env var
 */
class Bot
{
    private const BASE_URL = 'https://platform-api.max.ru';

    private string $token;
    private int    $timeoutSec;

    public function __construct(string $token, int $timeoutSec = 30)
    {
        if ($token === '') {
            throw new \InvalidArgumentException('Bot token must not be empty');
        }
        $this->token      = $token;
        $this->timeoutSec = $timeoutSec;
    }

    /** Create a Bot reading the token from the MAX_BOT_TOKEN environment variable. */
    public static function fromEnv(): self
    {
        $token = getenv('MAX_BOT_TOKEN');
        if ($token === false || $token === '') {
            throw new \RuntimeException('MAX_BOT_TOKEN environment variable is not set');
        }
        return new self($token);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bot info
    // ─────────────────────────────────────────────────────────────────────────

    /** GET /me — Returns info about the current bot. */
    public function getMe(): User
    {
        return User::fromArray($this->get('/me'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Messages
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /messages — Send a message to a chat/dialog by chat_id.
     *
     * `chatId` identifies a concrete dialog, group, or channel.
     * It is NOT the same as a user's global MAX userId.
     */
    public function sendMessageToChat(int $chatId, NewMessageBody $body): Message
    {
        return $this->sendMessage(['chat_id' => $chatId], $body);
    }

    /**
     * POST /messages — Send a message to a chat/dialog by chat_id with query options.
     */
    public function sendMessageToChatWithOptions(
        int $chatId,
        NewMessageBody $body,
        SendMessageOptions $options
    ): Message {
        return $this->sendMessage(['chat_id' => $chatId], $body, $options);
    }

    /**
     * POST /messages — Send a message to a user by global MAX userId.
     *
     * Use when you know the user's stable MAX identifier but not a specific dialog chatId.
     */
    public function sendMessageToUser(int $userId, NewMessageBody $body): Message
    {
        return $this->sendMessage(['user_id' => $userId], $body);
    }

    /**
     * POST /messages — Send a message to a user by global MAX userId with query options.
     */
    public function sendMessageToUserWithOptions(
        int $userId,
        NewMessageBody $body,
        SendMessageOptions $options
    ): Message {
        return $this->sendMessage(['user_id' => $userId], $body, $options);
    }

    /** Convenience: send a plain-text message to a chat by chatId. */
    public function sendTextToChat(int $chatId, string $text): Message
    {
        return $this->sendMessageToChat($chatId, NewMessageBody::text($text));
    }

    /** Convenience: send a plain-text message to a user by global MAX userId. */
    public function sendTextToUser(int $userId, string $text): Message
    {
        return $this->sendMessageToUser($userId, NewMessageBody::text($text));
    }

    /** Convenience: send a Markdown-formatted message to a chat by chatId. */
    public function sendMarkdownToChat(int $chatId, string $text): Message
    {
        return $this->sendMessageToChat($chatId, NewMessageBody::text($text)->withFormat('markdown'));
    }

    /** Convenience: send a Markdown-formatted message to a user by global MAX userId. */
    public function sendMarkdownToUser(int $userId, string $text): Message
    {
        return $this->sendMessageToUser($userId, NewMessageBody::text($text)->withFormat('markdown'));
    }

    /**
     * POST /messages — Shared sender for chat_id and user_id recipients.
     *
     * MAX returns either a direct Message payload or {"message": {...}};
     * parseMessage() normalizes both shapes.
     */
    private function sendMessage(array $recipientQuery, NewMessageBody $body, ?SendMessageOptions $options = null): Message
    {
        if ($options !== null) {
            $recipientQuery = array_merge($recipientQuery, $options->toQueryArray());
        }

        return $this->parseMessage(
            $this->post('/messages', $body->toArray(), $recipientQuery)
        );
    }

    /** PUT /messages — Edit an existing message. */
    public function editMessage(string $messageId, NewMessageBody $body): SimpleResult
    {
        return SimpleResult::fromArray(
            $this->put('/messages', $body->toArray(), ['message_id' => $messageId])
        );
    }

    /** DELETE /messages — Delete a message. */
    public function deleteMessage(string $messageId): SimpleResult
    {
        return SimpleResult::fromArray(
            $this->delete('/messages', ['message_id' => $messageId])
        );
    }

    /** GET /messages/{messageId} — Get a single message by ID. */
    public function getMessage(string $messageId): Message
    {
        return $this->parseMessage($this->get("/messages/{$messageId}"));
    }

    /**
     * GET /messages — Get messages from a chat.
     *
     * @param int|null $count Max number to return.
     * @param int|null $from  Start timestamp (ms).
     * @param int|null $to    End timestamp (ms).
     */
    public function getMessages(int $chatId, ?int $count = null, ?int $from = null, ?int $to = null): MessageList
    {
        $q = ['chat_id' => $chatId];
        if ($count !== null) { $q['count'] = $count; }
        if ($from  !== null) { $q['from']  = $from;  }
        if ($to    !== null) { $q['to']    = $to;    }
        return MessageList::fromArray($this->get('/messages', $q));
    }

    /**
     * GET /messages — Get one or more messages by message IDs.
     *
     * @param string[] $messageIds
     */
    public function getMessagesByIds(array $messageIds, ?int $count = null, ?int $from = null, ?int $to = null): MessageList
    {
        $q = ['message_ids' => implode(',', $messageIds)];
        if ($count !== null) { $q['count'] = $count; }
        if ($from  !== null) { $q['from']  = $from;  }
        if ($to    !== null) { $q['to']    = $to;    }
        return MessageList::fromArray($this->get('/messages', $q));
    }

    /** GET /videos/{videoToken} — Get video metadata and playback URLs. */
    public function getVideo(string $videoToken): VideoInfo
    {
        return VideoInfo::fromArray($this->get("/videos/{$videoToken}"));
    }

    /** POST /answers — Respond to an inline button callback. */
    public function answerCallback(AnswerCallbackBody $body): SimpleResult
    {
        return SimpleResult::fromArray(
            $this->post('/answers', $body->toArray(), ['callback_id' => $body->callbackId])
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Chats
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /chats — Get all group chats the bot is a member of.
     */
    public function getChats(?int $count = null, ?int $marker = null): ChatList
    {
        $q = [];
        if ($count  !== null) { $q['count']  = $count;  }
        if ($marker !== null) { $q['marker'] = $marker; }
        return ChatList::fromArray($this->get('/chats', $q));
    }

    /** GET /chats/{chatId} — Get info about a specific chat. */
    public function getChat(int $chatId): Chat
    {
        return Chat::fromArray($this->get("/chats/{$chatId}"));
    }

    /** PATCH /chats/{chatId} — Edit chat title/description. */
    public function editChat(int $chatId, EditChatBody $body): Chat
    {
        return Chat::fromArray($this->patch("/chats/{$chatId}", $body->toArray()));
    }

    /** DELETE /chats/{chatId} — Delete a chat. */
    public function deleteChat(int $chatId): SimpleResult
    {
        return SimpleResult::fromArray($this->delete("/chats/{$chatId}"));
    }

    /**
     * POST /chats/{chatId}/actions — Send a bot action (e.g. "typing_on").
     *
     * Valid values: "typing_on", "sending_photo", "sending_video",
     * "sending_audio", "sending_file", "mark_seen".
     *
     * NOTE: live MAX tests show successful API responses for "typing_on",
     * but the client-side typing indicator is not reliably visible.
     */
    public function sendAction(int $chatId, string $action): SimpleResult
    {
        return SimpleResult::fromArray(
            $this->post("/chats/{$chatId}/actions", ['action' => $action])
        );
    }

    /** POST /chats/{chatId}/actions — Send a typed sender action value. */
    public function sendSenderAction(int $chatId, string $action): SimpleResult
    {
        return $this->sendAction($chatId, $action);
    }

    /** Convenience: request a typing indicator. */
    public function sendTypingOn(int $chatId): SimpleResult
    {
        return $this->sendSenderAction($chatId, SenderAction::TYPING_ON);
    }

    /** Convenience: request an image sending indicator. */
    public function sendSendingImage(int $chatId): SimpleResult
    {
        return $this->sendSenderAction($chatId, SenderAction::SENDING_IMAGE);
    }

    /** Convenience: request a video sending indicator. */
    public function sendSendingVideo(int $chatId): SimpleResult
    {
        return $this->sendSenderAction($chatId, SenderAction::SENDING_VIDEO);
    }

    /** Convenience: request an audio sending indicator. */
    public function sendSendingAudio(int $chatId): SimpleResult
    {
        return $this->sendSenderAction($chatId, SenderAction::SENDING_AUDIO);
    }

    /** Convenience: request a file sending indicator. */
    public function sendSendingFile(int $chatId): SimpleResult
    {
        return $this->sendSenderAction($chatId, SenderAction::SENDING_FILE);
    }

    /** Convenience: mark a group chat as seen. */
    public function markSeen(int $chatId): SimpleResult
    {
        return $this->sendSenderAction($chatId, SenderAction::MARK_SEEN);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pinned messages
    // ─────────────────────────────────────────────────────────────────────────

    /** GET /chats/{chatId}/pin — Get the pinned message. */
    public function getPinnedMessage(int $chatId): PinnedMessage
    {
        return PinnedMessage::fromArray($this->get("/chats/{$chatId}/pin"));
    }

    /** PUT /chats/{chatId}/pin — Pin a message. */
    public function pinMessage(int $chatId, PinMessageBody $body): SimpleResult
    {
        return SimpleResult::fromArray($this->put("/chats/{$chatId}/pin", $body->toArray()));
    }

    /** DELETE /chats/{chatId}/pin — Unpin the pinned message. */
    public function unpinMessage(int $chatId): SimpleResult
    {
        return SimpleResult::fromArray($this->delete("/chats/{$chatId}/pin"));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Chat members
    // ─────────────────────────────────────────────────────────────────────────

    /** GET /chats/{chatId}/members */
    public function getMembers(int $chatId, ?int $count = null, ?int $marker = null): ChatMembersList
    {
        $q = [];
        if ($count  !== null) { $q['count']  = $count;  }
        if ($marker !== null) { $q['marker'] = $marker; }
        return ChatMembersList::fromArray($this->get("/chats/{$chatId}/members", $q));
    }

    /**
     * GET /chats/{chatId}/members — Get selected chat members by user IDs.
     *
     * @param int[] $userIds
     */
    public function getMembersByIds(int $chatId, array $userIds): ChatMembersList
    {
        return ChatMembersList::fromArray(
            $this->get("/chats/{$chatId}/members", ['user_ids' => implode(',', $userIds)])
        );
    }

    /** POST /chats/{chatId}/members — Add members. */
    public function addMembers(int $chatId, array $userIds): SimpleResult
    {
        return SimpleResult::fromArray(
            $this->post("/chats/{$chatId}/members", ['user_ids' => $userIds])
        );
    }

    /** DELETE /chats/{chatId}/members — Remove a member. */
    public function removeMember(int $chatId, int $userId): SimpleResult
    {
        return SimpleResult::fromArray(
            $this->delete("/chats/{$chatId}/members", ['user_id' => $userId])
        );
    }

    /** GET /chats/{chatId}/members/admins — Get admins. */
    public function getAdmins(int $chatId): ChatMembersList
    {
        return ChatMembersList::fromArray($this->get("/chats/{$chatId}/members/admins"));
    }

    /**
     * POST /chats/{chatId}/members/admins — Grant administrator rights.
     *
     * @param ChatAdmin[] $admins
     */
    public function addAdmins(int $chatId, array $admins): SimpleResult
    {
        return SimpleResult::fromArray(
            $this->post("/chats/{$chatId}/members/admins", [
                'admins' => array_map(static fn(ChatAdmin $admin) => $admin->toArray(), $admins),
            ])
        );
    }

    /** DELETE /chats/{chatId}/members/admins/{userId} — Revoke administrator rights. */
    public function removeAdmin(int $chatId, int $userId): SimpleResult
    {
        return SimpleResult::fromArray($this->delete("/chats/{$chatId}/members/admins/{$userId}"));
    }

    /** GET /chats/{chatId}/members/me — Get the bot's own membership info. */
    public function getMyMembership(int $chatId): ChatMember
    {
        return ChatMember::fromArray($this->get("/chats/{$chatId}/members/me"));
    }

    /** DELETE /chats/{chatId}/members/me — Leave a chat. */
    public function leaveChat(int $chatId): SimpleResult
    {
        return SimpleResult::fromArray($this->delete("/chats/{$chatId}/members/me"));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Subscriptions (Webhook)
    // ─────────────────────────────────────────────────────────────────────────

    /** GET /subscriptions — List current webhook subscriptions. */
    public function getSubscriptions(): SubscriptionList
    {
        return SubscriptionList::fromArray($this->get('/subscriptions'));
    }

    /** POST /subscriptions — Register a webhook URL. */
    public function subscribe(SubscribeBody $body): SimpleResult
    {
        return SimpleResult::fromArray($this->post('/subscriptions', $body->toArray()));
    }

    /** DELETE /subscriptions — Unsubscribe a webhook URL. */
    public function unsubscribe(string $url): SimpleResult
    {
        return SimpleResult::fromArray($this->delete('/subscriptions', ['url' => $url]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Long polling
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /updates — Poll for new updates once.
     *
     * @param int|null $marker  Offset from the previous call; pass null for the first call.
     * @param int|null $timeout Long-poll wait time in seconds (max 90).
     * @param int|null $limit   Maximum number of updates to return.
     */
    public function getUpdates(?int $marker = null, ?int $timeout = null, ?int $limit = null): UpdatesResponse
    {
        $q = [];
        if ($marker  !== null) { $q['marker']  = $marker;  }
        if ($timeout !== null) { $q['timeout']  = $timeout; }
        if ($limit   !== null) { $q['limit']    = $limit;   }
        return UpdatesResponse::fromArray($this->get('/updates', $q));
    }

    /**
     * GET /updates — Poll for raw JSON updates once.
     *
     * @param int|null $marker  Offset from the previous call.
     * @param int|null $timeout Long-poll wait time in seconds.
     * @param int|null $limit   Maximum number of updates to return.
     */
    public function getUpdatesRaw(?int $marker = null, ?int $timeout = null, ?int $limit = null): RawUpdatesResponse
    {
        $q = [];
        if ($marker  !== null) { $q['marker']  = $marker;  }
        if ($timeout !== null) { $q['timeout']  = $timeout; }
        if ($limit   !== null) { $q['limit']    = $limit;   }
        return RawUpdatesResponse::fromArray($this->get('/updates', $q));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // File uploads
    // ─────────────────────────────────────────────────────────────────────────

    /** POST /uploads — Get an upload URL for a given file type. */
    public function getUploadUrl(string $type): UploadEndpoint
    {
        return UploadEndpoint::fromArray($this->post('/uploads', [], ['type' => $type]));
    }

    /**
     * Full two-step file upload.
     *
     * Step 1: POST /uploads?type=<type>  → get upload URL (and pre-issued token for video/audio).
     * Step 2: POST <url> multipart/form-data → upload the file.
     *
     * Returns the attachment token to use in NewAttachment.
     *
     * MAX can return a token from the upload endpoint response, from the
     * multipart upload response, or for images as a `photos` token map. This
     * method accepts all forms and returns the first usable token.
     *
     * @param string $type     One of UploadType::IMAGE | VIDEO | AUDIO | FILE.
     * @param string $path     Path to a local file.
     * @param string $filename Filename sent in the multipart form.
     * @param string $mime     MIME type, e.g. "image/jpeg", "video/mp4".
     */
    public function uploadFile(string $type, string $path, string $filename, string $mime): string
    {
        if (!is_readable($path)) {
            throw new MaxException("File not readable: {$path}");
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new MaxException("Failed to read file: {$path}");
        }
        return $this->uploadBytes($type, $bytes, $filename, $mime);
    }

    /**
     * Like uploadFile(), but accepts raw bytes instead of a file path.
     *
     * @param string $bytes Raw binary content.
     */
    public function uploadBytes(string $type, string $bytes, string $filename, string $mime): string
    {
        $endpoint = $this->getUploadUrl($type);
        return $this->uploadBytesToEndpoint($endpoint, $bytes, $filename, $mime, $type);
    }

    /** Upload an image from disk and send it to a chat. */
    public function sendImageToChat(
        int $chatId,
        string $path,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadFileAndSend('chat', $chatId, UploadType::IMAGE, $path, $filename, $mime, $text);
    }

    /** Upload a video from disk and send it to a chat. */
    public function sendVideoToChat(
        int $chatId,
        string $path,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadFileAndSend('chat', $chatId, UploadType::VIDEO, $path, $filename, $mime, $text);
    }

    /** Upload an audio file from disk and send it to a chat. */
    public function sendAudioToChat(
        int $chatId,
        string $path,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadFileAndSend('chat', $chatId, UploadType::AUDIO, $path, $filename, $mime, $text);
    }

    /** Upload a generic file from disk and send it to a chat. */
    public function sendFileToChat(
        int $chatId,
        string $path,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadFileAndSend('chat', $chatId, UploadType::FILE, $path, $filename, $mime, $text);
    }

    /** Upload an image from disk and send it to a user. */
    public function sendImageToUser(
        int $userId,
        string $path,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadFileAndSend('user', $userId, UploadType::IMAGE, $path, $filename, $mime, $text);
    }

    /** Upload a video from disk and send it to a user. */
    public function sendVideoToUser(
        int $userId,
        string $path,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadFileAndSend('user', $userId, UploadType::VIDEO, $path, $filename, $mime, $text);
    }

    /** Upload an audio file from disk and send it to a user. */
    public function sendAudioToUser(
        int $userId,
        string $path,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadFileAndSend('user', $userId, UploadType::AUDIO, $path, $filename, $mime, $text);
    }

    /** Upload a generic file from disk and send it to a user. */
    public function sendFileToUser(
        int $userId,
        string $path,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadFileAndSend('user', $userId, UploadType::FILE, $path, $filename, $mime, $text);
    }

    /** Upload image bytes and send them to a chat. */
    public function sendImageBytesToChat(
        int $chatId,
        string $bytes,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadBytesAndSend('chat', $chatId, UploadType::IMAGE, $bytes, $filename, $mime, $text);
    }

    /** Upload video bytes and send them to a chat. */
    public function sendVideoBytesToChat(
        int $chatId,
        string $bytes,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadBytesAndSend('chat', $chatId, UploadType::VIDEO, $bytes, $filename, $mime, $text);
    }

    /** Upload audio bytes and send them to a chat. */
    public function sendAudioBytesToChat(
        int $chatId,
        string $bytes,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadBytesAndSend('chat', $chatId, UploadType::AUDIO, $bytes, $filename, $mime, $text);
    }

    /** Upload file bytes and send them to a chat. */
    public function sendFileBytesToChat(
        int $chatId,
        string $bytes,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadBytesAndSend('chat', $chatId, UploadType::FILE, $bytes, $filename, $mime, $text);
    }

    /** Upload image bytes and send them to a user. */
    public function sendImageBytesToUser(
        int $userId,
        string $bytes,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadBytesAndSend('user', $userId, UploadType::IMAGE, $bytes, $filename, $mime, $text);
    }

    /** Upload video bytes and send them to a user. */
    public function sendVideoBytesToUser(
        int $userId,
        string $bytes,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadBytesAndSend('user', $userId, UploadType::VIDEO, $bytes, $filename, $mime, $text);
    }

    /** Upload audio bytes and send them to a user. */
    public function sendAudioBytesToUser(
        int $userId,
        string $bytes,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadBytesAndSend('user', $userId, UploadType::AUDIO, $bytes, $filename, $mime, $text);
    }

    /** Upload file bytes and send them to a user. */
    public function sendFileBytesToUser(
        int $userId,
        string $bytes,
        string $filename,
        string $mime,
        ?string $text = null
    ): Message {
        return $this->uploadBytesAndSend('user', $userId, UploadType::FILE, $bytes, $filename, $mime, $text);
    }

    /**
     * Upload a local file and send the resulting attachment to a chat or user.
     */
    private function uploadFileAndSend(
        string $recipientType,
        int $recipientId,
        string $uploadType,
        string $path,
        string $filename,
        string $mime,
        ?string $text
    ): Message {
        if (!is_readable($path)) {
            throw new MaxException("File not readable: {$path}");
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new MaxException("Failed to read file: {$path}");
        }

        return $this->uploadBytesAndSend($recipientType, $recipientId, $uploadType, $bytes, $filename, $mime, $text);
    }

    /**
     * Upload raw bytes and send the resulting attachment to a chat or user.
     */
    private function uploadBytesAndSend(
        string $recipientType,
        int $recipientId,
        string $uploadType,
        string $bytes,
        string $filename,
        string $mime,
        ?string $text
    ): Message {
        $endpoint = $this->getUploadUrl($uploadType);
        $attachment = $this->uploadBytesToEndpointAsAttachment($endpoint, $bytes, $filename, $mime, $uploadType);

        return $this->sendUploadedAttachment($recipientType, $recipientId, $attachment, $text);
    }

    /** Upload bytes to an already-issued upload endpoint and return a token. */
    private function uploadBytesToEndpoint(
        UploadEndpoint $endpoint,
        string $bytes,
        string $filename,
        string $mime,
        string $uploadType
    ): string {
        $body = $this->uploadBytesToEndpointBody($endpoint, $bytes, $filename, $mime);

        return $this->tokenFromUploadResponse($endpoint, $body, $uploadType);
    }

    /** Upload bytes to an already-issued upload endpoint and return a sendable attachment. */
    private function uploadBytesToEndpointAsAttachment(
        UploadEndpoint $endpoint,
        string $bytes,
        string $filename,
        string $mime,
        string $uploadType
    ): NewAttachment {
        $body = $this->uploadBytesToEndpointBody($endpoint, $bytes, $filename, $mime);

        return $this->attachmentFromUploadResponse($endpoint, $body, $uploadType);
    }

    /** POST raw bytes as multipart/form-data to a MAX upload endpoint. */
    private function uploadBytesToEndpointBody(UploadEndpoint $endpoint, string $bytes, string $filename, string $mime): string
    {
        if ($endpoint->url === '') {
            throw new MaxException('No upload URL in response');
        }

        $boundary = '----MaxoxideBoundary' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"data\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $bytes;
        $body .= "\r\n--{$boundary}--\r\n";

        $ch = curl_init($endpoint->url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSec);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: multipart/form-data; boundary={$boundary}",
            'Content-Length: ' . strlen($body),
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new MaxException("Upload cURL error: {$err}");
        }
        if ($status < 200 || $status >= 300) {
            throw new MaxException("Upload HTTP error {$status}: {$raw}", $status);
        }

        return (string) $raw;
    }

    /** Build a NewAttachment from a multipart upload response. */
    private function attachmentFromUploadResponse(UploadEndpoint $endpoint, string $body, string $uploadType): NewAttachment
    {
        $response = $this->decodeUploadResponse($body);
        if ($uploadType === UploadType::IMAGE && $response !== null && $response->photos !== null && $response->photos !== []) {
            return NewAttachment::imagePhotos($response->photos);
        }

        $token = $this->tokenFromUploadResponse($endpoint, $body, $uploadType);
        switch ($uploadType) {
            case UploadType::IMAGE:
                $attachment = NewAttachment::image($token);
                break;
            case UploadType::VIDEO:
                $attachment = NewAttachment::video($token);
                break;
            case UploadType::AUDIO:
                $attachment = NewAttachment::audio($token);
                break;
            default:
                $attachment = NewAttachment::file($token);
        }

        return $attachment;
    }

    /** Extract the first usable token from upload endpoint and multipart responses. */
    private function tokenFromUploadResponse(UploadEndpoint $endpoint, string $body, string $uploadType): string
    {
        $response = $this->decodeUploadResponse($body);
        $bodyToken = $response !== null ? $response->token : null;
        $photoToken = $uploadType === UploadType::IMAGE && $response !== null
            ? $this->firstPhotoToken($response)
            : null;
        $token = $bodyToken ?? $photoToken ?? $endpoint->token;

        if ($token === null || $token === '') {
            $message = ($uploadType === UploadType::IMAGE || $uploadType === UploadType::FILE)
                ? 'No token in upload response body or upload endpoint response for image/file'
                : 'No token in upload endpoint response or upload response body for video/audio';
            throw new MaxException($message);
        }

        return $token;
    }

    /** Decode a multipart upload JSON body, returning null for non-object JSON. */
    private function decodeUploadResponse(string $body): ?UploadResponse
    {
        $decoded = json_decode($body, true);
        $response = is_array($decoded) ? UploadResponse::fromArray($decoded) : null;

        return $response;
    }

    /** Return the first token from an image upload photos map. */
    private function firstPhotoToken(UploadResponse $response): ?string
    {
        $token = null;
        if ($response->photos !== null) {
            foreach ($response->photos as $photo) {
                $token = $photo->token;
                break;
            }
        }

        return $token;
    }

    /** Send an already-uploaded attachment, retrying while MAX is still processing it. */
    private function sendUploadedAttachment(
        string $recipientType,
        int $recipientId,
        NewAttachment $attachment,
        ?string $text
    ): Message {
        $retryDelaysUs = [0, 500000, 1000000, 2000000, 4000000, 8000000];
        foreach ($retryDelaysUs as $index => $delayUs) {
            if ($delayUs > 0) {
                usleep($delayUs);
            }

            try {
                $body = NewMessageBody::textOpt($text)->withAttachment($attachment);
                if ($recipientType === 'chat') {
                    return $this->sendMessageToChat($recipientId, $body);
                }

                return $this->sendMessageToUser($recipientId, $body);
            } catch (MaxException $e) {
                if (!$this->isAttachmentNotProcessedError($e) || $index === count($retryDelaysUs) - 1) {
                    throw $e;
                }
            }
        }

        throw new MaxException('Failed to send uploaded attachment');
    }

    /** Return true when MAX reports an uploaded attachment is not processed yet. */
    private function isAttachmentNotProcessedError(MaxException $e): bool
    {
        return strpos($e->getMessage(), '.not.processed') !== false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bot commands (experimental)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Attempt to set the list of commands shown to users.
     *
     * NOTE: the public MAX REST docs do not list a write endpoint for bot
     * commands. Live POST /me/commands requests return 404. Kept for
     * future MAX platform support.
     *
     * @param BotCommand[] $commands
     */
    public function setMyCommands(array $commands): SimpleResult
    {
        return SimpleResult::fromArray(
            $this->post('/me/commands', ['commands' => array_map(
                static fn(BotCommand $c) => $c->toArray(), $commands
            )])
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal HTTP helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, [], $query);
    }

    private function post(string $path, array $body = [], array $query = []): array
    {
        return $this->request('POST', $path, $body, $query);
    }

    private function put(string $path, array $body = [], array $query = []): array
    {
        return $this->request('PUT', $path, $body, $query);
    }

    private function patch(string $path, array $body = [], array $query = []): array
    {
        return $this->request('PATCH', $path, $body, $query);
    }

    private function delete(string $path, array $query = []): array
    {
        return $this->request('DELETE', $path, [], $query);
    }

    /** Core cURL dispatcher. All public methods route through here. */
    private function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $url = self::BASE_URL . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: ' . $this->token,
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSec);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new MaxException("cURL error: {$err}");
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new MaxException("Invalid JSON response: {$raw}", $status);
        }

        if ($status < 200 || $status >= 300) {
            $msg = (string) ($data['message'] ?? $data['error'] ?? $raw);
            throw new MaxException("API error {$status}: {$msg}", $status);
        }

        // MAX sometimes wraps the real object in a 'message' key.
        if (isset($data['message']) && is_array($data['message']) && count($data) === 1) {
            return $data['message'];
        }

        return $data;
    }

    /** Parse a raw array that could be either a direct Message or a wrapped one. */
    private function parseMessage(array $data): Message
    {
        // Wrapped: {"message": {...}}
        if (isset($data['message']) && is_array($data['message'])) {
            return Message::fromArray($data['message']);
        }
        return Message::fromArray($data);
    }
}
