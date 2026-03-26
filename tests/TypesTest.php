<?php

declare(strict_types=1);

namespace Maxoxide\Tests;

use Maxoxide\AnswerCallbackBody;
use Maxoxide\Attachment;
use Maxoxide\Button;
use Maxoxide\Callback;
use Maxoxide\Chat;
use Maxoxide\ChatList;
use Maxoxide\ChatMember;
use Maxoxide\ChatMembersList;
use Maxoxide\Dispatcher;
use Maxoxide\EditChatBody;
use Maxoxide\KeyboardPayload;
use Maxoxide\Message;
use Maxoxide\MessageBody;
use Maxoxide\NewAttachment;
use Maxoxide\NewMessageBody;
use Maxoxide\PinMessageBody;
use Maxoxide\Recipient;
use Maxoxide\SimpleResult;
use Maxoxide\SubscribeBody;
use Maxoxide\Update;
use Maxoxide\UploadType;
use Maxoxide\User;
use PHPUnit\Framework\TestCase;

class TypesTest extends TestCase
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeMessageArray(int $chatId, string $text): array
    {
        return [
            'sender'    => ['user_id' => 1, 'name' => 'Alice'],
            'recipient' => ['chat_id' => $chatId, 'chat_type' => 'dialog'],
            'timestamp' => 1700000000,
            'body'      => ['mid' => 'mid_001', 'seq' => 1, 'text' => $text],
        ];
    }

    private function makeCallbackUpdate(string $payload): Update
    {
        return Update::fromArray([
            'update_type' => 'message_callback',
            'timestamp'   => 1700000000,
            'callback'    => [
                'callback_id' => 'cb_001',
                'user'        => ['user_id' => 2, 'name' => 'Bob'],
                'payload'     => $payload,
                'timestamp'   => 1700000000,
            ],
        ]);
    }

    // ─── Serde round-trips ───────────────────────────────────────────────────

    public function testUpdateMessageCreatedRoundtrip(): void
    {
        $data = [
            'update_type' => 'message_created',
            'timestamp'   => 1700000000,
            'message'     => [
                'sender'    => ['user_id' => 1, 'name' => 'Alice'],
                'recipient' => ['chat_id' => 42, 'chat_type' => 'dialog'],
                'timestamp' => 1700000000,
                'body'      => ['mid' => 'mid_1', 'seq' => 1, 'text' => 'hello'],
            ],
        ];

        $update = Update::fromArray($data);
        $this->assertSame('message_created', $update->type);
        $this->assertNotNull($update->message);
        $this->assertSame(42, $update->message->chatId());
        $this->assertSame('hello', $update->message->text());
        $this->assertSame('mid_1', $update->message->messageId());
    }

    public function testUpdateMessageCallbackRoundtrip(): void
    {
        $data = [
            'update_type' => 'message_callback',
            'timestamp'   => 1700000000,
            'callback'    => [
                'callback_id' => 'cb_1',
                'user'        => ['user_id' => 2, 'name' => 'Bob'],
                'payload'     => 'btn:ok',
                'timestamp'   => 1700000000,
            ],
        ];

        $update = Update::fromArray($data);
        $this->assertSame('message_callback', $update->type);
        $this->assertNotNull($update->callback);
        $this->assertSame('btn:ok', $update->callback->payload);
    }

    public function testUpdateBotStartedRoundtrip(): void
    {
        $data = [
            'update_type' => 'bot_started',
            'timestamp'   => 1700000000,
            'chat_id'     => 99,
            'user'        => ['user_id' => 3, 'name' => 'Carol'],
            'payload'     => '/start',
        ];

        $update = Update::fromArray($data);
        $this->assertSame('bot_started', $update->type);
        $this->assertSame(99, $update->chatId);
        $this->assertSame('/start', $update->payload);
    }

    public function testRecipientDistinguishesChatIdAndUserId(): void
    {
        $msg = Message::fromArray($this->makeMessageArray(223921237, 'hello'));
        $this->assertSame(223921237, $msg->chatId());
        $this->assertSame(1, $msg->sender->userId);
    }

    public function testRecipientRoundtripPreservesBothIds(): void
    {
        $data = [
            'sender'    => ['user_id' => 5465382, 'name' => 'Konstantin'],
            'recipient' => ['chat_id' => 223921237, 'chat_type' => 'dialog', 'user_id' => 5465382],
            'timestamp' => 1700000000,
            'body'      => ['mid' => 'mid_1', 'seq' => 1, 'text' => 'hello'],
        ];
        $msg = Message::fromArray($data);
        $this->assertSame(223921237, $msg->chatId());
        $this->assertSame(5465382, $msg->recipient->userId);
    }

    // ─── NewMessageBody builder ───────────────────────────────────────────────

    public function testNewMessageBodyText(): void
    {
        $body = NewMessageBody::text('Hello, Max!');
        $arr  = $body->toArray();
        $this->assertSame('Hello, Max!', $arr['text']);
        $this->assertArrayNotHasKey('attachments', $arr);
    }

    public function testNewMessageBodyWithKeyboard(): void
    {
        $keyboard = new KeyboardPayload([[Button::callback('OK', 'btn:ok')]]);
        $body     = NewMessageBody::text('Choose:')->withKeyboard($keyboard);
        $arr      = $body->toArray();

        $this->assertArrayHasKey('attachments', $arr);
        $this->assertCount(1, $arr['attachments']);
        $this->assertSame('inline_keyboard', $arr['attachments'][0]['type']);
    }

    public function testNewMessageBodySerializationFull(): void
    {
        $keyboard = new KeyboardPayload([[
            Button::callback('Yes ✅', 'answer:yes'),
            Button::callback('No ❌',  'answer:no'),
        ]]);
        $body = NewMessageBody::text('Are you sure?')
            ->withKeyboard($keyboard)
            ->withFormat('markdown');
        $arr = $body->toArray();

        $this->assertSame('Are you sure?', $arr['text']);
        $this->assertSame('markdown', $arr['format']);
        $buttons = $arr['attachments'][0]['payload']['buttons'][0];
        $this->assertSame('callback', $buttons[0]['type']);
        $this->assertSame('answer:yes', $buttons[0]['payload']);
        $this->assertSame('answer:no',  $buttons[1]['payload']);
    }

    // ─── Button ──────────────────────────────────────────────────────────────

    public function testButtonCallbackSerialization(): void
    {
        $btn = Button::callback('Click', 'click:1');
        $arr = $btn->toArray();
        $this->assertSame('callback', $arr['type']);
        $this->assertSame('click:1', $arr['payload']);
        $this->assertArrayNotHasKey('intent', $arr);
    }

    public function testButtonLinkSerialization(): void
    {
        $btn = Button::link('Docs', 'https://dev.max.ru');
        $arr = $btn->toArray();
        $this->assertSame('link', $arr['type']);
        $this->assertSame('https://dev.max.ru', $arr['url']);
    }

    public function testButtonWithIntent(): void
    {
        $btn = Button::callback('Delete', 'del', 'negative');
        $arr = $btn->toArray();
        $this->assertSame('negative', $arr['intent']);
    }

    // ─── AnswerCallbackBody ───────────────────────────────────────────────────

    public function testAnswerCallbackBodyDefaults(): void
    {
        $body = new AnswerCallbackBody('cb_123');
        $body->notification = 'done!';
        $arr = $body->toArray();
        $this->assertSame('done!', $arr['notification']);
        $this->assertArrayNotHasKey('message', $arr);
    }

    // ─── SubscribeBody ────────────────────────────────────────────────────────

    public function testSubscribeBodyWithSecret(): void
    {
        $body = new SubscribeBody('https://bot.example.com/webhook');
        $body->updateTypes = ['message_created'];
        $body->secret      = 'my_secret_abc';
        $arr = $body->toArray();
        $this->assertSame('my_secret_abc', $arr['secret']);
        $this->assertSame(['message_created'], $arr['update_types']);
        $this->assertArrayNotHasKey('version', $arr);
    }

    public function testSubscribeBodyNoSecretOmitted(): void
    {
        $body = new SubscribeBody('https://bot.example.com/webhook');
        $arr  = $body->toArray();
        $this->assertArrayNotHasKey('secret', $arr);
        $this->assertArrayNotHasKey('update_types', $arr);
    }

    // ─── Dispatcher filter matching ──────────────────────────────────────────

    private function makeMessageUpdate(int $chatId, string $text): Update
    {
        return Update::fromArray([
            'update_type' => 'message_created',
            'timestamp'   => 0,
            'message'     => $this->makeMessageArray($chatId, $text),
        ]);
    }

    public function testDispatcherFilterMessage(): void
    {
        $hits  = 0;
        $bot   = $this->createMock(\Maxoxide\Bot::class);
        $dp    = new Dispatcher($bot);
        $dp->onMessage(function () use (&$hits) { $hits++; });

        $dp->dispatch($this->makeMessageUpdate(1, 'hi'));
        $dp->dispatch($this->makeCallbackUpdate('btn'));

        $this->assertSame(1, $hits);  // only message_created matched
    }

    public function testDispatcherCommandMatchesPrefix(): void
    {
        $hits = 0;
        $bot  = $this->createMock(\Maxoxide\Bot::class);
        $dp   = new Dispatcher($bot);
        $dp->onCommand('/start', function () use (&$hits) { $hits++; });

        $dp->dispatch($this->makeMessageUpdate(1, '/start payload'));
        $dp->dispatch($this->makeMessageUpdate(1, '/help'));

        $this->assertSame(1, $hits);
    }

    public function testDispatcherCallbackPayloadExact(): void
    {
        $hits = 0;
        $bot  = $this->createMock(\Maxoxide\Bot::class);
        $dp   = new Dispatcher($bot);
        $dp->onCallbackPayload('color:red', function () use (&$hits) { $hits++; });

        $dp->dispatch($this->makeCallbackUpdate('color:red'));
        $dp->dispatch($this->makeCallbackUpdate('color:blue'));

        $this->assertSame(1, $hits);
    }

    public function testDispatcherFirstMatchWins(): void
    {
        $log = [];
        $bot = $this->createMock(\Maxoxide\Bot::class);
        $dp  = new Dispatcher($bot);
        $dp->onCommand('/start', function () use (&$log) { $log[] = 'command'; });
        $dp->onMessage(function () use (&$log) { $log[] = 'message'; });

        $dp->dispatch($this->makeMessageUpdate(1, '/start'));

        $this->assertSame(['command'], $log);  // onMessage must NOT fire
    }

    public function testDispatcherCustomFilter(): void
    {
        $hits = 0;
        $bot  = $this->createMock(\Maxoxide\Bot::class);
        $dp   = new Dispatcher($bot);
        $dp->onFilter(
            fn(\Maxoxide\Update $u) => $u->timestamp === 999,
            function () use (&$hits) { $hits++; }
        );

        $dp->dispatch(Update::fromArray([
            'update_type' => 'message_created',
            'timestamp'   => 999,
            'message'     => $this->makeMessageArray(1, ''),
        ]));
        $dp->dispatch(Update::fromArray([
            'update_type' => 'message_created',
            'timestamp'   => 1,
            'message'     => $this->makeMessageArray(1, ''),
        ]));

        $this->assertSame(1, $hits);
    }

    // ─── Attachment deserialization ──────────────────────────────────────────

    public function testAttachmentImageDeserialization(): void
    {
        $att = Attachment::fromArray([
            'type'    => 'image',
            'payload' => ['url' => 'https://cdn.example.com/photo.jpg', 'token' => 'tok123'],
        ]);
        $this->assertSame('image', $att->type);
        $this->assertSame('https://cdn.example.com/photo.jpg', $att->url);
        $this->assertSame('tok123', $att->token);
    }

    public function testAttachmentInlineKeyboardDeserialization(): void
    {
        $att = Attachment::fromArray([
            'type'    => 'inline_keyboard',
            'payload' => [
                'buttons' => [[
                    ['type' => 'callback', 'text' => 'Click', 'payload' => 'click:1'],
                ]],
            ],
        ]);
        $this->assertSame('inline_keyboard', $att->type);
        $this->assertCount(1, $att->buttons);
        $this->assertSame('click:1', $att->buttons[0][0]->payload);
    }

    public function testAttachmentUnknownFallsBackToRaw(): void
    {
        $raw = ['type' => 'future_type', 'payload' => ['foo' => 'bar']];
        $att = Attachment::fromArray($raw);
        $this->assertSame('future_type', $att->type);
        $this->assertSame($raw, $att->raw);
    }

    // ─── NewAttachment serialization ─────────────────────────────────────────

    public function testNewAttachmentInlineKeyboardRoundtrip(): void
    {
        $keyboard = new KeyboardPayload([[Button::callback('Click', 'click:1')]]);
        $att = NewAttachment::inlineKeyboard($keyboard);
        $arr = $att->toArray();
        $this->assertSame('inline_keyboard', $arr['type']);
        $this->assertSame('click:1', $arr['payload']['buttons'][0][0]['payload']);
    }

    public function testNewAttachmentImageToken(): void
    {
        $arr = NewAttachment::image('tok_abc')->toArray();
        $this->assertSame('image', $arr['type']);
        $this->assertSame('tok_abc', $arr['payload']['token']);
    }

    // ─── UploadType constants ─────────────────────────────────────────────────

    public function testUploadTypeConstants(): void
    {
        $this->assertSame('image', UploadType::IMAGE);
        $this->assertSame('video', UploadType::VIDEO);
        $this->assertSame('audio', UploadType::AUDIO);
        $this->assertSame('file',  UploadType::FILE);
    }

    // ─── EditChatBody ─────────────────────────────────────────────────────────

    public function testEditChatBodyOmitsNullFields(): void
    {
        $body = new EditChatBody();
        $body->title = 'New Title';
        $arr = $body->toArray();
        $this->assertSame('New Title', $arr['title']);
        $this->assertArrayNotHasKey('description', $arr);
        $this->assertArrayNotHasKey('notify', $arr);
    }

    // ─── PinMessageBody ───────────────────────────────────────────────────────

    public function testPinMessageBodySerialization(): void
    {
        $body = new PinMessageBody('mid_42', true);
        $arr  = $body->toArray();
        $this->assertSame('mid_42', $arr['message_id']);
        $this->assertTrue($arr['notify']);
    }

    public function testPinMessageBodyOmitsNotifyWhenNull(): void
    {
        $body = new PinMessageBody('mid_42');
        $arr  = $body->toArray();
        $this->assertArrayNotHasKey('notify', $arr);
    }

    // ─── SimpleResult ─────────────────────────────────────────────────────────

    public function testSimpleResultFromArray(): void
    {
        $r = SimpleResult::fromArray(['success' => true, 'message' => 'ok']);
        $this->assertTrue($r->success);
        $this->assertSame('ok', $r->message);
    }

    // ─── User ─────────────────────────────────────────────────────────────────

    public function testUserFromArrayWithOptionalFields(): void
    {
        $u = User::fromArray([
            'user_id'  => 42,
            'name'     => 'Test',
            'username' => 'tester',
            'is_bot'   => false,
        ]);
        $this->assertSame(42, $u->userId);
        $this->assertSame('tester', $u->username);
        $this->assertFalse($u->isBot);
        $this->assertNull($u->avatarUrl);
    }

    // ─── Chat ────────────────────────────────────────────────────────────────

    public function testChatFromArray(): void
    {
        $c = Chat::fromArray([
            'chat_id' => 100,
            'type'    => 'chat',
            'title'   => 'My Group',
        ]);
        $this->assertSame(100, $c->chatId);
        $this->assertSame('My Group', $c->title);
        $this->assertNull($c->description);
    }
}
