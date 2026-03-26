<?php

declare(strict_types=1);

namespace Maxoxide {

    final class BotRequestStub
    {
        /** @var array<string, array<string, mixed>> */
        public static array $handles = [];
        /** @var array<int, array<string, mixed>> */
        public static array $responseQueue = [];

        public static function reset(): void
        {
            self::$handles = [];
            self::$responseQueue = [];
        }

        public static function queueResponse(array $response): void
        {
            self::$responseQueue[] = $response;
        }

        /** @return array<string, mixed> */
        public static function lastHandle(): array
        {
            $lastKey = array_key_last(self::$handles);
            if ($lastKey === null) {
                throw new \RuntimeException('No stubbed cURL handles were recorded');
            }

            return self::$handles[$lastKey];
        }
    }

    function curl_init($url = null)
    {
        if (BotRequestStub::$responseQueue === []) {
            return \curl_init($url);
        }

        $handleId = 'stub-' . (string) (count(BotRequestStub::$handles) + 1);
        $response = array_shift(BotRequestStub::$responseQueue);
        if (!is_array($response)) {
            throw new \RuntimeException('No queued stub response for cURL request');
        }

        BotRequestStub::$handles[$handleId] = [
            'url' => $url,
            'options' => [],
            'response' => $response,
        ];

        return $handleId;
    }

    function curl_setopt($ch, $option, $value): bool
    {
        if (is_string($ch) && isset(BotRequestStub::$handles[$ch])) {
            BotRequestStub::$handles[$ch]['options'][$option] = $value;
            return true;
        }

        return \curl_setopt($ch, $option, $value);
    }

    function curl_exec($ch)
    {
        if (is_string($ch) && isset(BotRequestStub::$handles[$ch])) {
            return BotRequestStub::$handles[$ch]['response']['raw'];
        }

        return \curl_exec($ch);
    }

    function curl_getinfo($ch, $option = 0)
    {
        if (is_string($ch) && isset(BotRequestStub::$handles[$ch]) && $option === CURLINFO_HTTP_CODE) {
            return BotRequestStub::$handles[$ch]['response']['status'];
        }

        return \curl_getinfo($ch, $option);
    }

    function curl_error($ch): string
    {
        if (is_string($ch) && isset(BotRequestStub::$handles[$ch])) {
            return (string) (BotRequestStub::$handles[$ch]['response']['error'] ?? '');
        }

        return \curl_error($ch);
    }

    function curl_close($ch): void
    {
        if (is_string($ch) && isset(BotRequestStub::$handles[$ch])) {
            return;
        }

        \curl_close($ch);
    }
}

namespace Maxoxide\Tests {

    use Maxoxide\Bot;
    use Maxoxide\BotRequestStub;
    use Maxoxide\NewAttachment;
    use Maxoxide\NewMessageBody;
    use PHPUnit\Framework\TestCase;

    final class BotSendMessageTest extends TestCase
    {
        protected function setUp(): void
        {
            BotRequestStub::reset();
        }

        public function testSendTextToChatPostsMessageBodyWithChatIdQuery(): void
        {
            BotRequestStub::queueResponse([
                'raw' => $this->json([
                    'sender' => ['user_id' => 1, 'name' => 'Max Bot'],
                    'recipient' => ['chat_id' => 249197540, 'chat_type' => 'dialog'],
                    'timestamp' => 1700000000,
                    'body' => ['mid' => 'mid_1', 'seq' => 1, 'text' => 'hello from test'],
                ]),
                'status' => 200,
            ]);

            $bot = new Bot('secret-token', 15);
            $message = $bot->sendTextToChat(249197540, 'hello from test');

            $this->assertSame(249197540, $message->chatId());
            $this->assertSame('hello from test', $message->text());

            $request = BotRequestStub::lastHandle();
            $this->assertSame('https://platform-api.max.ru/messages?chat_id=249197540', $request['url']);
            $this->assertSame('POST', $request['options'][CURLOPT_CUSTOMREQUEST]);
            $this->assertSame(15, $request['options'][CURLOPT_TIMEOUT]);
            $this->assertJsonStringEqualsJsonString(
                $this->json(['text' => 'hello from test']),
                (string) $request['options'][CURLOPT_POSTFIELDS]
            );
            $this->assertContains('Authorization: secret-token', $request['options'][CURLOPT_HTTPHEADER]);
            $this->assertContains('Accept: application/json', $request['options'][CURLOPT_HTTPHEADER]);
            $this->assertContains('Content-Type: application/json', $request['options'][CURLOPT_HTTPHEADER]);
        }

        public function testSendMessageToUserParsesWrappedMessagePayload(): void
        {
            BotRequestStub::queueResponse([
                'raw' => $this->json([
                    'message' => [
                        'sender' => ['user_id' => 1, 'name' => 'Max Bot'],
                        'recipient' => ['chat_id' => 249197540, 'chat_type' => 'dialog', 'user_id' => 5465382],
                        'timestamp' => 1700000000,
                        'body' => [
                            'mid' => 'mid_2',
                            'seq' => 2,
                            'text' => 'payload test',
                            'attachments' => [
                                ['type' => 'file', 'payload' => ['token' => 'upload-token']],
                            ],
                        ],
                    ],
                ]),
                'status' => 200,
            ]);

            $bot = new Bot('secret-token');
            $body = NewMessageBody::text('payload test')
                ->withAttachment(NewAttachment::file('upload-token'));

            $message = $bot->sendMessageToUser(5465382, $body);

            $this->assertSame(249197540, $message->chatId());
            $this->assertSame(5465382, $message->recipient->userId);
            $this->assertSame('payload test', $message->text());

            $request = BotRequestStub::lastHandle();
            $this->assertSame('https://platform-api.max.ru/messages?user_id=5465382', $request['url']);
            $this->assertJsonStringEqualsJsonString(
                $this->json([
                    'text' => 'payload test',
                    'attachments' => [
                        ['type' => 'file', 'payload' => ['token' => 'upload-token']],
                    ],
                ]),
                (string) $request['options'][CURLOPT_POSTFIELDS]
            );
        }

        private function json(array $payload): string
        {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $this->assertNotFalse($json);

            return $json;
        }
    }
}
