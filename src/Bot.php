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
     * POST /messages — Send a message to a user by global MAX userId.
     *
     * Use when you know the user's stable MAX identifier but not a specific dialog chatId.
     */
    public function sendMessageToUser(int $userId, NewMessageBody $body): Message
    {
        return $this->sendMessage(['user_id' => $userId], $body);
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
    private function sendMessage(array $recipientQuery, NewMessageBody $body): Message
    {
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
    public function getSubscriptions(): array
    {
        $d = $this->get('/subscriptions');
        return $d['subscriptions'] ?? [];
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

    // ─────────────────────────────────────────────────────────────────────────
    // File uploads
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Full two-step file upload.
     *
     * Step 1: POST /uploads?type=<type>  → get upload URL (and pre-issued token for video/audio).
     * Step 2: POST <url> multipart/form-data → upload the file.
     *
     * Returns the attachment token to use in NewAttachment.
     *
     * For image/file: token comes from the upload response body.
     * For video/audio: token is pre-issued in step 1.
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
        // Step 1 — request the upload URL.
        $endpoint = $this->post('/uploads', [], ['type' => $type]);

        $uploadUrl    = (string) ($endpoint['url'] ?? '');
        $preIssuedToken = isset($endpoint['token']) ? (string) $endpoint['token'] : null;

        if ($uploadUrl === '') {
            throw new MaxException('No upload URL in response');
        }

        // Step 2 — POST the file as multipart/form-data.
        $boundary = '----MaxoxideBoundary' . bin2hex(random_bytes(8));
        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"data\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $bytes;
        $body .= "\r\n--{$boundary}--\r\n";

        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSec,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: multipart/form-data; boundary={$boundary}",
                "Content-Length: " . strlen($body),
            ],
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new MaxException("Upload cURL error: {$err}");
        }
        if ($status < 200 || $status >= 300) {
            throw new MaxException("Upload HTTP error {$status}: {$raw}", $status);
        }

        // For video/audio: token was pre-issued.
        if ($type === UploadType::VIDEO || $type === UploadType::AUDIO) {
            if ($preIssuedToken === null) {
                throw new MaxException('No token in upload endpoint response for video/audio');
            }
            return $preIssuedToken;
        }

        // For image/file: token comes from the upload response body.
        $decoded = json_decode((string) $raw, true);
        $token   = is_array($decoded) && isset($decoded['token']) ? (string) $decoded['token'] : null;
        if ($token === null) {
            throw new MaxException('No token in upload response body for image/file');
        }
        return $token;
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
