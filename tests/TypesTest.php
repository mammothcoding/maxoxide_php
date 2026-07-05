<?php

declare(strict_types=1);

namespace Maxoxide\Tests;

use Maxoxide\AnswerCallbackBody;
use Maxoxide\Attachment;
use Maxoxide\AttachmentKind;
use Maxoxide\Button;
use Maxoxide\Callback;
use Maxoxide\Chat;
use Maxoxide\ChatAdmin;
use Maxoxide\ChatAdminPermission;
use Maxoxide\ChatList;
use Maxoxide\ChatMember;
use Maxoxide\ChatMembersList;
use Maxoxide\ChatStatus;
use Maxoxide\Dispatcher;
use Maxoxide\EditChatBody;
use Maxoxide\EditMyInfoBody;
use Maxoxide\Filter;
use Maxoxide\KeyboardPayload;
use Maxoxide\MarkupElement;
use Maxoxide\Message;
use Maxoxide\MessageFormat;
use Maxoxide\MessageBody;
use Maxoxide\NewAttachment;
use Maxoxide\NewMessageBody;
use Maxoxide\PinMessageBody;
use Maxoxide\Recipient;
use Maxoxide\RemoveMemberOptions;
use Maxoxide\SendMessageOptions;
use Maxoxide\SenderAction;
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
        $this->assertSame('suspended', ChatStatus::SUSPENDED);
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

    public function testEditMyInfoBodySerialization(): void
    {
        $body = new EditMyInfoBody();
        $body->firstName = 'Max';
        $body->description = 'Bot profile';
        $body->commands = [new \Maxoxide\BotCommand('live', 'Run live test')];

        $arr = $body->toArray();

        $this->assertSame('Max', $arr['first_name']);
        $this->assertSame('Bot profile', $arr['description']);
        $this->assertSame('live', $arr['commands'][0]['name']);
        $this->assertArrayNotHasKey('last_name', $arr);
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
            'participants' => ['1' => 1700000000, '2' => 1700000001],
            'messages_count' => 123,
        ]);
        $this->assertSame(100, $c->chatId);
        $this->assertSame('My Group', $c->title);
        $this->assertNull($c->description);
        $this->assertSame(1700000000, $c->participants[1]);
        $this->assertSame(123, $c->messagesCount);
    }

    public function testUserDeserializesFirstNameAndLegacyName(): void
    {
        $current = User::fromArray([
            'user_id' => 1,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
        ]);
        $legacy = User::fromArray([
            'user_id' => 2,
            'name' => 'Legacy',
        ]);

        $this->assertSame('Alice Smith', $current->displayName());
        $this->assertSame('Alice', $current->firstName);
        $this->assertSame('Legacy', $legacy->firstName);
        $this->assertSame('Legacy', $legacy->displayName());
    }

    public function testUnknownUpdatePreservesRawAndNullableTimestamp(): void
    {
        $raw = [
            'update_type' => 'future_update',
            'payload' => ['x' => 1],
        ];

        $update = Update::fromArray($raw);

        $this->assertSame('future_update', $update->type);
        $this->assertSame('future_update', $update->updateType());
        $this->assertNull($update->timestamp());
        $this->assertSame(0, $update->timestampOrDefault());
        $this->assertSame($raw, $update->raw());
    }

    public function testAttachmentFlatLocationDeserialization(): void
    {
        $att = Attachment::fromArray([
            'type' => 'location',
            'latitude' => 56.98666000366211,
            'longitude' => 40.977272033691406,
        ]);

        $this->assertSame(AttachmentKind::LOCATION, $att->kind());
        $this->assertSame(56.98666000366211, $att->latitude);
        $this->assertSame(40.977272033691406, $att->longitude);
    }

    public function testButtonOpenAppAndClipboardSerialization(): void
    {
        $openApp = Button::openAppFull('Open', 'mini_app', 'payload', 123)->toArray();
        $clipboard = Button::clipboard('Copy', 'copy_payload')->toArray();

        $this->assertSame('open_app', $openApp['type']);
        $this->assertSame('mini_app', $openApp['web_app']);
        $this->assertSame('payload', $openApp['payload']);
        $this->assertSame(123, $openApp['contact_id']);
        $this->assertSame('clipboard', $clipboard['type']);
        $this->assertSame('copy_payload', $clipboard['payload']);
    }

    public function testButtonChatSerializationAndDeserialization(): void
    {
        $button = Button::chatFull('Create chat', 'Support', 'Help desk', 'start-1', 1234);
        $arr = $button->toArray();

        $this->assertSame('chat', $arr['type']);
        $this->assertSame('Support', $arr['chat_title']);
        $this->assertSame('Help desk', $arr['chat_description']);
        $this->assertSame('start-1', $arr['start_payload']);
        $this->assertSame(1234, $arr['uuid']);

        $parsed = Button::fromArray($arr);
        $this->assertSame('Support', $parsed->chatTitle);
        $this->assertSame(1234, $parsed->uuid);
    }

    public function testNewAttachmentImagePhotosSerialization(): void
    {
        $arr = NewAttachment::imagePhotos([
            'photo-1' => ['token' => 'photo_token'],
        ])->toArray();

        $this->assertSame('image', $arr['type']);
        $this->assertSame('photo_token', $arr['payload']['photos']['photo-1']['token']);
        $this->assertArrayNotHasKey('token', $arr['payload']);
    }

    public function testNewMessageBodyLinkAndOptionsSerialization(): void
    {
        $body = NewMessageBody::text('Reply')
            ->withAttachment(NewAttachment::file('file_token'))
            ->withReplyTo('mid_reply')
            ->withNotify(false)
            ->withFormat(MessageFormat::MARKDOWN);
        $options = SendMessageOptions::disableLinkPreview(true);

        $arr = $body->toArray();

        $this->assertSame('Reply', $arr['text']);
        $this->assertFalse($arr['notify']);
        $this->assertSame('reply', $arr['link']['type']);
        $this->assertSame('mid_reply', $arr['link']['mid']);
        $this->assertSame('markdown', $arr['format']);
        $this->assertSame(['disable_link_preview' => 'true'], $options->toQueryArray());
    }

    public function testRemoveMemberOptionsSerialization(): void
    {
        $this->assertSame(['block' => 'true'], RemoveMemberOptions::block(true)->toQueryArray());
        $this->assertSame(['block' => 'false'], RemoveMemberOptions::block(false)->toQueryArray());
        $this->assertSame([], (new RemoveMemberOptions())->toQueryArray());
    }

    public function testMessageMarkupParsing(): void
    {
        $body = MessageBody::fromArray([
            'mid' => 'mid_markup',
            'seq' => 1,
            'text' => 'hello docs',
            'markup' => [
                ['type' => 'strong', 'from' => 0, 'length' => 5],
                ['type' => 'link', 'from' => 6, 'length' => 4, 'url' => 'https://dev.max.ru'],
                ['type' => 'future_markup', 'from' => 0, 'length' => 1, 'extra' => true],
                ['type' => 'link', 'from' => 0],
            ],
        ]);

        $this->assertCount(4, $body->markup);
        $this->assertSame('strong', $body->markup[0]->kind());
        $this->assertSame('https://dev.max.ru', $body->markup[1]->url);
        $this->assertSame('future_markup', $body->markup[2]->kind());
        $this->assertSame('link', $body->markup[3]->kind());
        $this->assertNull($body->markup[3]->length);
    }

    public function testMarkupElementSerializesKnownFields(): void
    {
        $markup = MarkupElement::fromArray([
            'type' => 'user_mention',
            'from' => 0,
            'length' => 4,
            'user_link' => 'max://user/1',
            'user_id' => 1,
        ]);

        $arr = $markup->toArray();

        $this->assertSame('user_mention', $arr['type']);
        $this->assertSame('max://user/1', $arr['user_link']);
        $this->assertSame(1, $arr['user_id']);
    }

    public function testAttachmentContactExtrasAndVcfHelpers(): void
    {
        $vcf = "BEGIN:VCARD\nTEL;TYPE=CELL:+1 (234) 567-890\nEND:VCARD";
        $hash = hash_hmac('sha256', $vcf, 'secret-token');
        $att = Attachment::fromArray([
            'type' => 'contact',
            'payload' => [
                'name' => 'Alice',
                'vcf_info' => $vcf,
                'hash' => $hash,
                'tam_info' => ['user_id' => 9, 'first_name' => 'Alice'],
            ],
        ]);

        $this->assertSame(AttachmentKind::CONTACT, $att->kind());
        $this->assertSame($hash, $att->hash);
        $this->assertNotNull($att->maxInfo);
        $this->assertSame(9, $att->maxInfo->userId);
        $this->assertSame(['+1234567890'], $att->phonesFromVcf());
        $this->assertTrue($att->validateHash('secret-token'));
        $this->assertFalse($att->validateHash('wrong-token'));
    }

    public function testAttachmentShareDataAndMediaExtras(): void
    {
        $share = Attachment::fromArray([
            'type' => 'share',
            'payload' => ['url' => 'https://max.ru', 'token' => 'share-token'],
            'title' => 'MAX',
            'description' => 'Messenger',
            'image_url' => 'https://cdn.example.test/share.jpg',
        ]);
        $data = Attachment::fromArray(['type' => 'data', 'data' => 'opaque']);
        $video = Attachment::fromArray([
            'type' => 'video',
            'payload' => ['token' => 'video-token'],
            'thumbnail' => ['url' => 'https://cdn.example.test/thumb.jpg'],
            'width' => 640,
            'height' => 360,
            'duration' => 42,
            'transcription' => 'hello',
        ]);

        $this->assertSame(AttachmentKind::SHARE, $share->kind());
        $this->assertSame('MAX', $share->title);
        $this->assertSame('https://cdn.example.test/share.jpg', $share->imageUrl);
        $this->assertSame(AttachmentKind::DATA, $data->kind());
        $this->assertSame('opaque', $data->data);
        $this->assertSame(640, $video->width);
        $this->assertSame(42, $video->duration);
        $this->assertSame('hello', $video->transcription);
        $this->assertSame('https://cdn.example.test/thumb.jpg', $video->thumbnail->url);
    }

    public function testNewTypedUpdatesParsing(): void
    {
        $user = ['user_id' => 7, 'first_name' => 'Alice'];
        $botStopped = Update::fromArray([
            'update_type' => 'bot_stopped',
            'timestamp' => 11,
            'chat_id' => 42,
            'user' => $user,
            'user_locale' => 'en',
        ]);
        $dialogMuted = Update::fromArray([
            'update_type' => 'dialog_muted',
            'timestamp' => 12,
            'chat_id' => 42,
            'user' => $user,
            'muted_until' => 123456,
        ]);
        $chatCreated = Update::fromArray([
            'update_type' => 'message_chat_created',
            'timestamp' => 13,
            'chat' => ['chat_id' => 100, 'type' => 'chat', 'title' => 'Created'],
            'message_id' => 'mid_chat',
            'start_payload' => 'start-1',
        ]);
        $editedMissing = Update::fromArray([
            'update_type' => 'message_edited',
            'timestamp' => 14,
        ]);

        $this->assertSame('bot_stopped', $botStopped->type);
        $this->assertSame('en', $botStopped->userLocale);
        $this->assertSame('dialog_muted', $dialogMuted->type);
        $this->assertSame(123456, $dialogMuted->mutedUntil);
        $this->assertSame('message_chat_created', $chatCreated->type);
        $this->assertSame(100, $chatCreated->chat->chatId);
        $this->assertSame('start-1', $chatCreated->startPayload);
        $this->assertSame('message_edited_missing', $editedMissing->type);
        $this->assertSame('message_edited', $editedMissing->updateType());
        $this->assertNull($editedMissing->raw());
    }

    public function testDispatcherNewUpdateFilters(): void
    {
        $dialogRemoved = Update::fromArray([
            'update_type' => 'dialog_removed',
            'timestamp' => 11,
            'chat_id' => 42,
            'user' => ['user_id' => 7, 'first_name' => 'Alice'],
        ]);
        $chatCreated = Update::fromArray([
            'update_type' => 'message_chat_created',
            'timestamp' => 13,
            'chat' => ['chat_id' => 100, 'type' => 'chat'],
            'message_id' => 'mid_chat',
        ]);

        $this->assertTrue(Filter::dialogRemoved()->matches($dialogRemoved));
        $this->assertFalse(Filter::dialogMuted()->matches($dialogRemoved));
        $this->assertTrue(Filter::messageChatCreated()->matches($chatCreated));

        $hits = 0;
        $bot = $this->createMock(\Maxoxide\Bot::class);
        $dp = new Dispatcher($bot);
        $dp->onDialogRemoved(function () use (&$hits) { $hits++; });
        $dp->dispatch($dialogRemoved);

        $this->assertSame(1, $hits);
    }

    public function testFilterCompositionTextAndAttachmentKinds(): void
    {
        $update = Update::fromArray([
            'update_type' => 'message_created',
            'timestamp' => 0,
            'message' => [
                'sender' => ['user_id' => 7, 'name' => 'Sender'],
                'recipient' => ['chat_id' => 42, 'chat_type' => 'dialog'],
                'timestamp' => 0,
                'body' => [
                    'mid' => 'mid_file',
                    'seq' => 1,
                    'text' => 'ping payload',
                    'attachments' => [
                        ['type' => 'file', 'payload' => ['token' => 'tok', 'filename' => 'report.pdf']],
                    ],
                ],
            ],
        ]);

        $filter = Filter::message()
            ->andFilter(Filter::chat(42))
            ->andFilter(Filter::textContains('ping'))
            ->andFilter(Filter::hasAttachmentType(AttachmentKind::FILE));

        $this->assertTrue($filter->matches($update));
        $this->assertTrue(Filter::textExact('ping payload')->matches($update));
        $this->assertTrue(Filter::textRegex('^ping')->matches($update));
        $this->assertTrue(Filter::sender(7)->matches($update));
        $this->assertTrue(Filter::chat(7)->negate()->matches($update));
        $this->assertFalse(Filter::hasMedia()->matches($update));
    }

    public function testChatAdminAndSenderActionValues(): void
    {
        $admin = new ChatAdmin(7, [ChatAdminPermission::ADD_ADMINS], 'Ops');

        $this->assertSame([
            'user_id' => 7,
            'permissions' => ['add_admins'],
            'alias' => 'Ops',
        ], $admin->toArray());
        $this->assertSame('edit', ChatAdminPermission::EDIT);
        $this->assertSame('delete', ChatAdminPermission::DELETE);
        $this->assertSame('sending_photo', SenderAction::SENDING_IMAGE);
    }
}
