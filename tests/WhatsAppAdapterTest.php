<?php

namespace BootDesk\ChatSDK\WhatsApp\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\AdapterHasMessagingWindow;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\Tests\Helpers\MemoryStateAdapter;
use BootDesk\ChatSDK\WhatsApp\WhatsAppAdapter;
use BootDesk\ChatSDK\WhatsApp\WhatsAppTemplate;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class WhatsAppAdapterTest extends TestCase
{
    private WhatsAppAdapter $adapter;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;

        $mockClient = new class implements ClientInterface
        {
            private Psr17Factory $factory;

            public function __construct()
            {
                $this->factory = new Psr17Factory;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $uri = (string) $request->getUri();

                if (str_contains($uri, 'messages')) {
                    return $this->factory->createResponse(200)->withBody(
                        $this->factory->createStream(json_encode([
                            'messaging_product' => 'whatsapp',
                            'contacts' => [['input' => '1234567890', 'wa_id' => '1234567890']],
                            'messages' => [['id' => 'wamid.HBgMMTIzNDU2Nzg5MB==']],
                        ]))
                    );
                }

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode(['success' => true]))
                );
            }
        };

        $this->adapter = new WhatsAppAdapter(
            accessToken: 'test_token',
            phoneNumberId: 'phone123',
            appSecret: 'my_app_secret',
            verifyToken: 'my_verify_token',
            httpClient: $mockClient,
            psrFactory: $this->factory,
        );
    }

    public function test_get_name(): void
    {
        $this->assertSame('whatsapp', $this->adapter->getName());
    }

    public function test_thread_id_encoding(): void
    {
        $id = $this->adapter->encodeThreadId([
            'phoneNumberId' => 'phone123',
            'userWaId' => '5511999999999',
        ]);
        $this->assertSame('whatsapp:phone123:5511999999999', $id);
    }

    public function test_thread_id_decoding(): void
    {
        $decoded = $this->adapter->decodeThreadId('whatsapp:phone123:5511999999999');
        $this->assertSame('phone123', $decoded['phoneNumberId']);
        $this->assertSame('5511999999999', $decoded['userWaId']);
    }

    public function test_channel_id_from_thread(): void
    {
        $this->assertSame('5511999999999', $this->adapter->channelIdFromThreadId('whatsapp:phone123:5511999999999'));
    }

    public function test_verify_webhook_get_challenge(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=my_verify_token&hub_challenge=test_challenge');

        $response = $this->adapter->verifyWebhook($request);
        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test_challenge', (string) $response->getBody());
    }

    public function test_verify_webhook_get_wrong_token(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=test');

        $response = $this->adapter->verifyWebhook($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_verify_webhook_post_valid_signature(): void
    {
        $body = '{"entry":[]}';
        $signature = 'sha256='.hash_hmac('sha256', $body, 'my_app_secret');

        $request = $this->factory->createServerRequest('POST', '/webhooks/whatsapp')
            ->withHeader('x-hub-signature-256', $signature)
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->verifyWebhook($request);
        $this->assertNull($result);
    }

    public function test_verify_webhook_post_invalid_signature(): void
    {
        $body = '{"entry":[]}';

        $request = $this->factory->createServerRequest('POST', '/webhooks/whatsapp')
            ->withHeader('x-hub-signature-256', 'sha256=invalid')
            ->withBody($this->factory->createStream($body));

        $this->expectException(AuthenticationException::class);
        $this->adapter->verifyWebhook($request);
    }

    public function test_parse_webhook_message(): void
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '123',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+15551234567',
                            'phone_number_id' => 'phone123',
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'John Doe'],
                            'wa_id' => '5511999999999',
                        ]],
                        'messages' => [[
                            'from' => '5511999999999',
                            'id' => 'wamid.test123',
                            'text' => ['body' => 'Hello bot'],
                            'timestamp' => '1700000000',
                            'type' => 'text',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $signature = 'sha256='.hash_hmac('sha256', $body, 'my_app_secret');

        $request = $this->factory->createServerRequest('POST', '/webhooks/whatsapp')
            ->withHeader('x-hub-signature-256', $signature)
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('wamid.test123', $message->id);
        $this->assertSame('whatsapp:phone123:5511999999999', $message->threadId);
        $this->assertSame('5511999999999', $message->author->id);
        $this->assertSame('John Doe', $message->author->name);
        $this->assertSame('Hello bot', $message->text);
        $this->assertTrue($message->isDM);
    }

    public function test_parse_interactive_reply(): void
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '123',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => 'phone123', 'display_phone_number' => '+1'],
                        'contacts' => [['profile' => ['name' => 'Jane'], 'wa_id' => '999']],
                        'messages' => [[
                            'from' => '999',
                            'id' => 'wamid.reply1',
                            'type' => 'interactive',
                            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'chat:yes', 'title' => 'Yes']],
                            'timestamp' => '1700000000',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $signature = 'sha256='.hash_hmac('sha256', $body, 'my_app_secret');
        $request = $this->factory->createServerRequest('POST', '/webhooks/whatsapp')
            ->withHeader('x-hub-signature-256', $signature)
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertSame('Yes', $message->text);
    }

    public function test_post_message(): void
    {
        $sent = $this->adapter->postMessage(
            'whatsapp:phone123:5511999999999',
            PostableMessage::text('Hello WhatsApp')
        );

        $this->assertSame('wamid.HBgMMTIzNDU2Nzg5MB==', $sent->id);
    }

    public function test_edit_message_resends(): void
    {
        $sent = $this->adapter->editMessage(
            'whatsapp:phone123:5511999999999',
            'wamid.old',
            PostableMessage::text('Updated')
        );

        $this->assertNotNull($sent->id);
    }

    public function test_post_message_with_card(): void
    {
        $card = Card::make()
            ->header('Choose')
            ->actions([Button::primary('Go', 'go')]);

        $sent = $this->adapter->postMessage(
            'whatsapp:phone123:5511999999999',
            PostableMessage::card($card)
        );

        $this->assertNotNull($sent->id);
    }

    public function test_add_reaction(): void
    {
        $this->adapter->addReaction('whatsapp:phone123:999', 'wamid.1', '👍');
        $this->assertTrue(true);
    }

    public function test_start_typing_is_noop(): void
    {
        $this->adapter->startTyping('whatsapp:phone123:999');
        $this->assertTrue(true);
    }

    public function test_start_typing_sends_typing_indicator_via_state(): void
    {
        $mockClient = new class implements ClientInterface
        {
            public array $captured = [];

            private Psr17Factory $factory;

            public function __construct()
            {
                $this->factory = new Psr17Factory;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode(['success' => true]))
                );
            }
        };

        $state = new MemoryStateAdapter;
        $adapter = new WhatsAppAdapter(
            accessToken: 'test_token',
            httpClient: $mockClient,
            phoneNumberId: 'phone123',
            psrFactory: $this->factory,
        );

        $chat = new Chat(state: $state);
        $adapter->initialize($chat);

        $state->set('typing_msg:whatsapp:phone123:5511999999999', 'wamid.HBgLMTY1MDM4Nzk0MzkVAgARGBJDQjZCMzlEQUE4OTJBMTE4RTUA');

        $adapter->startTyping('whatsapp:phone123:5511999999999');

        $sentBody = json_decode((string) $mockClient->captured[0]->getBody(), true);
        $this->assertSame('whatsapp', $sentBody['messaging_product']);
        $this->assertSame('5511999999999', $sentBody['to']);
        $this->assertSame('read', $sentBody['status']);
        $this->assertSame('wamid.HBgLMTY1MDM4Nzk0MzkVAgARGBJDQjZCMzlEQUE4OTJBMTE4RTUA', $sentBody['message_id']);
        $this->assertSame('text', $sentBody['typing_indicator']['type']);
    }

    public function test_fetch_messages_returns_empty(): void
    {
        $result = $this->adapter->fetchMessages('whatsapp:phone123:999');
        $this->assertCount(0, $result->messages);
    }

    public function test_get_user_returns_id(): void
    {
        $user = $this->adapter->getUser('5511999999999');
        $this->assertSame('5511999999999', $user->id);
    }

    public function test_open_dm_returns_thread_id(): void
    {
        $result = $this->adapter->openDM('5511999999999');
        $this->assertStringContainsString('5511999999999', $result);
    }

    public function test_get_format_converter(): void
    {
        $this->assertNotNull($this->adapter->getFormatConverter());
    }

    public function test_initialize_sets_bot_user_id(): void
    {
        $state = new MemoryStateAdapter;
        $chat = new Chat(state: $state);
        $this->adapter->initialize($chat);
        $this->assertSame('phone123', $this->adapter->getBotUserId());
    }

    public function test_disconnect_is_noop(): void
    {
        $this->adapter->disconnect();
        $this->assertTrue(true);
    }

    public function test_parse_webhook_invalid_json_throws(): void
    {
        $this->expectException(AdapterException::class);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream('not json'));

        $this->adapter->parseWebhook($request);
    }

    public function test_parse_webhook_no_message_throws(): void
    {
        $this->expectException(AdapterException::class);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream('{"entry":[{"changes":[{"field":"messages","value":{}}]}]}'));

        $this->adapter->parseWebhook($request);
    }

    public function test_parse_webhook_skips_reactions(): void
    {
        $body = json_encode([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'p123'],
                        'messages' => [['type' => 'reaction', 'reaction' => ['emoji' => '👍']]],
                    ],
                ]],
            ]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $this->expectException(AdapterException::class);
        $this->adapter->parseWebhook($request);
    }

    public function test_parse_webhook_with_bsuid(): void
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '123',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+15551234567',
                            'phone_number_id' => 'phone123',
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Jane Smith'],
                            'user_id' => 'US.13491208655302741918',
                        ]],
                        'messages' => [[
                            'from_user_id' => 'US.13491208655302741918',
                            'id' => 'wamid.bsuid001',
                            'text' => ['body' => 'Hello via BSUID'],
                            'timestamp' => '1749416383',
                            'type' => 'text',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/whatsapp')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('wamid.bsuid001', $message->id);
        $this->assertSame('whatsapp:phone123:US.13491208655302741918', $message->threadId);
        $this->assertSame('US.13491208655302741918', $message->author->id);
        $this->assertSame('Jane Smith', $message->author->name);
        $this->assertSame('Hello via BSUID', $message->text);
    }

    public function test_post_message_uses_recipient_for_bsuid(): void
    {
        $mockClient = new class implements ClientInterface
        {
            public array $captured = [];

            private Psr17Factory $factory;

            public function __construct()
            {
                $this->factory = new Psr17Factory;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'messaging_product' => 'whatsapp',
                        'contacts' => [['input' => 'US.13491208655302741918', 'user_id' => 'US.13491208655302741918']],
                        'messages' => [['id' => 'wamid.bsuid002']],
                    ]))
                );
            }
        };

        $adapter = new WhatsAppAdapter(
            accessToken: 'test_token',
            httpClient: $mockClient,
            phoneNumberId: 'phone123',
            psrFactory: $this->factory,
        );

        $adapter->postMessage(
            'whatsapp:phone123:US.13491208655302741918',
            PostableMessage::text('Hello BSUID')
        );

        $sentBody = json_decode((string) $mockClient->captured[0]->getBody(), true);
        $this->assertArrayNotHasKey('to', $sentBody);
        $this->assertSame('US.13491208655302741918', $sentBody['recipient']);
        $this->assertSame('whatsapp', $sentBody['messaging_product']);
    }

    public function test_post_template_message(): void
    {
        $mockClient = new class implements ClientInterface
        {
            public array $captured = [];

            private Psr17Factory $factory;

            public function __construct()
            {
                $this->factory = new Psr17Factory;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'messaging_product' => 'whatsapp',
                        'contacts' => [['input' => '5511999999999', 'wa_id' => '5511999999999']],
                        'messages' => [['id' => 'wamid.tpl001']],
                    ]))
                );
            }
        };

        $adapter = new WhatsAppAdapter(
            accessToken: 'test_token',
            httpClient: $mockClient,
            phoneNumberId: 'phone123',
            psrFactory: $this->factory,
        );

        $tpl = WhatsAppTemplate::create('order_confirmation', 'en_US')
            ->bodyParam('John')
            ->bodyParam('#12345');

        $sent = $adapter->postMessage(
            'whatsapp:phone123:5511999999999',
            PostableMessage::template($tpl)
        );

        $this->assertSame('wamid.tpl001', $sent->id);

        $sentBody = json_decode((string) $mockClient->captured[0]->getBody(), true);
        $this->assertSame('template', $sentBody['type']);
        $this->assertSame('order_confirmation', $sentBody['template']['name']);
        $this->assertSame('en_US', $sentBody['template']['language']['code']);
        $this->assertSame('John', $sentBody['template']['components'][0]['parameters'][0]['text']);
        $this->assertSame('#12345', $sentBody['template']['components'][0]['parameters'][1]['text']);
    }

    public function test_encode_thread_id_defaults(): void
    {
        $id = $this->adapter->encodeThreadId([]);
        $this->assertStringContainsString('phone123', $id);
    }

    public function test_decode_thread_id_partial(): void
    {
        $decoded = $this->adapter->decodeThreadId('whatsapp');
        $this->assertSame('phone123', $decoded['phoneNumberId']);
    }

    public function test_fetch_thread(): void
    {
        $info = $this->adapter->fetchThread('whatsapp:phone123:999');
        $this->assertSame('whatsapp:phone123:999', $info->id);
    }

    public function test_fetch_channel_info_returns_null(): void
    {
        $this->assertNull($this->adapter->fetchChannelInfo('phone123'));
    }

    public function test_create_response_returns_null(): void
    {
        $this->assertNull($this->adapter->createResponse());
    }

    public function test_remove_reaction(): void
    {
        $this->adapter->removeReaction('whatsapp:phone123:5511999999999', 'msg_1', '👍');
        $this->assertTrue(true);
    }

    public function test_stream_returns_null_for_empty(): void
    {
        $result = $this->adapter->stream('whatsapp:phone123:999', []);
        $this->assertNull($result);
    }

    public function test_stream_collects_and_posts(): void
    {
        $sent = $this->adapter->stream(
            'whatsapp:phone123:5511999999999',
            ['Hello ', 'world', '!'],
        );

        $this->assertNotNull($sent);
        $this->assertSame('wamid.HBgMMTIzNDU2Nzg5MB==', $sent->id);
    }

    public function test_delete_message(): void
    {
        $this->adapter->deleteMessage('whatsapp:phone123:5511999999999', 'wamid.123');
        $this->assertTrue(true);
    }

    public function test_api_call_throws_authentication_exception_on_auth_error(): void
    {
        $factory = new Psr17Factory;
        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $f = new Psr17Factory;

                return $f->createResponse(200)->withBody(
                    $f->createStream(json_encode(['error' => ['code' => 401, 'message' => 'Invalid credentials']]))
                );
            }
        };

        $adapter = new WhatsAppAdapter(
            accessToken: 'bad-token',
            phoneNumberId: 'phone123',
            appSecret: 'secret',
            verifyToken: 'verify',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $this->expectException(AuthenticationException::class);
        $adapter->postMessage('whatsapp:phone123:5511999999999', PostableMessage::text('test'));
    }

    public function test_implements_messaging_window(): void
    {
        $this->assertInstanceOf(AdapterHasMessagingWindow::class, $this->adapter);
        $this->assertSame(86400, $this->adapter->getMessagingWindowSeconds());
    }

    public function test_tracking_key(): void
    {
        $key = $this->adapter->getTrackingKey('whatsapp:phone123:5511999999999');
        $this->assertSame('whatsapp:5511999999999', $key);
    }

    public function test_parse_status_delivered(): void
    {
        $body = json_encode([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'phone123'],
                        'statuses' => [[
                            'id' => 'wamid.delivered123',
                            'recipient_id' => '5511999999999',
                            'status' => 'delivered',
                            'timestamp' => '1700000000',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/whatsapp')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertSame('delivered', $result['type']);
        $this->assertSame(['wamid.delivered123'], $result['messageIds']);
        $this->assertSame(1700000000, $result['timestamp']);
    }

    public function test_parse_status_read(): void
    {
        $body = json_encode([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'phone123'],
                        'statuses' => [[
                            'id' => 'wamid.read456',
                            'recipient_id' => '5511999999998',
                            'status' => 'read',
                            'timestamp' => '1700000001',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/whatsapp')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertSame('read', $result['type']);
        $this->assertSame('5511999999998', $result['userId']);
    }

    public function test_parse_status_ignores_sent(): void
    {
        $body = json_encode([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'phone123'],
                        'statuses' => [[
                            'id' => 'wamid.sent789',
                            'recipient_id' => '5511999999997',
                            'status' => 'sent',
                            'timestamp' => '1700000002',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/whatsapp')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseStatus($request));
    }

    public function test_parse_status_not_messages_field(): void
    {
        $body = json_encode([
            'entry' => [[
                'changes' => [[
                    'field' => 'other',
                    'value' => ['statuses' => [['status' => 'delivered']]],
                ]],
            ]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/whatsapp')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseStatus($request));
    }
}
