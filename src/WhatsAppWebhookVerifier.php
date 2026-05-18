<?php

namespace BootDesk\ChatSDK\WhatsApp;

use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use Psr\Http\Message\ServerRequestInterface;

class WhatsAppWebhookVerifier
{
    public function __construct(
        private readonly string $appSecret,
        private readonly string $verifyToken,
    ) {}

    public function verifySignature(string $body, string $signature): bool
    {
        $expected = 'sha256='.hash_hmac('sha256', $body, $this->appSecret);

        return hash_equals($expected, $signature);
    }

    public function verifyWebhookSignature(ServerRequestInterface $request, string $body): void
    {
        $signature = $request->getHeaderLine('x-hub-signature-256');

        if ($signature === '' || ! $this->verifySignature($body, $signature)) {
            throw new AuthenticationException('Invalid WhatsApp webhook signature');
        }
    }

    public function handleVerificationChallenge(ServerRequestInterface $request): ?string
    {
        $params = $request->getQueryParams();
        $mode = $params['hub_mode'] ?? '';
        $token = $params['hub_verify_token'] ?? '';
        $challenge = $params['hub_challenge'] ?? '';

        if ($mode === 'subscribe' && $token === $this->verifyToken) {
            return $challenge;
        }

        return null;
    }
}
