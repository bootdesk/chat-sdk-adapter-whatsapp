<?php

namespace BootDesk\ChatSDK\WhatsApp\Tests;

use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\WhatsApp\WhatsAppWebhookVerifier;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

class WhatsAppWebhookVerifierTest extends TestCase
{
    private WhatsAppWebhookVerifier $verifier;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->verifier = new WhatsAppWebhookVerifier('my_app_secret', 'my_verify_token');
        $this->factory = new Psr17Factory;
    }

    public function test_valid_signature(): void
    {
        $body = '{"entry":[]}';
        $signature = 'sha256='.hash_hmac('sha256', $body, 'my_app_secret');

        $this->assertTrue($this->verifier->verifySignature($body, $signature));
    }

    public function test_invalid_signature(): void
    {
        $this->assertFalse($this->verifier->verifySignature('{"entry":[]}', 'sha256=invalid'));
    }

    public function test_verification_challenge(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=my_verify_token&hub_challenge=challenge123');

        $result = $this->verifier->handleVerificationChallenge($request);
        $this->assertSame('challenge123', $result);
    }

    public function test_verification_challenge_wrong_token(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=challenge123');

        $result = $this->verifier->handleVerificationChallenge($request);
        $this->assertNull($result);
    }

    public function test_verify_webhook_signature_throws_on_invalid(): void
    {
        $body = '{"entry":[]}';
        $request = $this->factory->createServerRequest('POST', '/webhooks/whatsapp')
            ->withHeader('x-hub-signature-256', 'sha256=invalid')
            ->withBody($this->factory->createStream($body));

        $this->expectException(AuthenticationException::class);
        $this->verifier->verifyWebhookSignature($request, $body);
    }
}
