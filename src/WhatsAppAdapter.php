<?php

namespace BootDesk\ChatSDK\WhatsApp;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\AdapterHasMessagingWindow;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\HandlesReactions;
use BootDesk\ChatSDK\Core\Contracts\HandlesStatuses;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class WhatsAppAdapter implements Adapter, AdapterHasMessagingWindow, HandlesReactions, HandlesStatuses
{
    protected ?string $botUserId = null;

    protected WhatsAppFormatConverter $formatConverter;

    protected ?WhatsAppWebhookVerifier $webhookVerifier = null;

    protected ?StateAdapter $state = null;

    protected FileUploadConverter $fileUploadConverter;

    public function __construct(
        protected readonly string $accessToken,
        protected readonly ClientInterface $httpClient,
        protected readonly string $phoneNumberId,
        ?string $appSecret = null,
        ?string $verifyToken = null,
        protected readonly string $apiUrl = 'https://graph.facebook.com/v21.0',
        protected readonly ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
    ) {
        $this->formatConverter = new WhatsAppFormatConverter;
        $this->fileUploadConverter = $fileUploadConverter ?? new NullFileUploadConverter;

        if ($appSecret !== null && $verifyToken !== null) {
            $this->webhookVerifier = new WhatsAppWebhookVerifier($appSecret, $verifyToken);
        }
    }

    public function getName(): string
    {
        return 'whatsapp';
    }

    public function getMessagingWindowSeconds(): ?int
    {
        return 86400; // 24 hours
    }

    public function getTrackingKey(string $threadId): string
    {
        $parts = explode(':', $threadId, 3);
        $userWaId = $parts[2] ?? $parts[1] ?? '';

        return "{$this->getName()}:{$userWaId}";
    }

    public function getBotUserId(): ?string
    {
        return $this->botUserId;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        // GET = verification challenge
        if (strtoupper($request->getMethod()) === 'GET') {
            if ($this->webhookVerifier instanceof WhatsAppWebhookVerifier) {
                $challenge = $this->webhookVerifier->handleVerificationChallenge($request);
                if ($challenge !== null) {
                    return $factory->createResponse(200)
                        ->withBody($factory->createStream($challenge));
                }
            }

            return $factory->createResponse(403);
        }

        // POST = verify HMAC signature
        $body = (string) $request->getBody();

        if ($this->webhookVerifier instanceof WhatsAppWebhookVerifier) {
            $this->webhookVerifier->verifyWebhookSignature($request, $body);
        }

        return null;
    }

    public function parseReaction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return null;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? $this->phoneNumberId;

                foreach ($messages as $msg) {
                    $reaction = $msg['reaction'] ?? null;
                    if ($reaction === null || ($msg['type'] ?? '') !== 'reaction') {
                        continue;
                    }

                    $rawEmoji = $reaction['emoji'] ?? '';
                    $added = $rawEmoji !== '';

                    $threadId = $this->encodeThreadId([
                        'phoneNumberId' => $phoneNumberId,
                        'userWaId' => $msg['from'],
                    ]);

                    return [
                        'emoji' => $rawEmoji,
                        'rawEmoji' => $rawEmoji,
                        'added' => $added,
                        'threadId' => $threadId,
                        'messageId' => $reaction['message_id'],
                        'userId' => $msg['from'],
                        'raw' => $payload,
                    ];
                }
            }
        }

        return null;
    }

    public function parseStatus(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return null;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? $this->phoneNumberId;

                foreach ($value['statuses'] ?? [] as $status) {
                    $statusType = $status['status'] ?? '';
                    if ($statusType !== 'delivered' && $statusType !== 'read') {
                        continue;
                    }

                    $recipientId = $status['recipient_id'] ?? '';
                    $threadId = $this->encodeThreadId([
                        'phoneNumberId' => $phoneNumberId,
                        'userWaId' => $recipientId,
                    ]);

                    return [
                        'type' => $statusType,
                        'messageIds' => [$status['id'] ?? ''],
                        'threadId' => $threadId,
                        'userId' => $recipientId,
                        'timestamp' => (int) ($status['timestamp'] ?? 0),
                        'raw' => $payload,
                    ];
                }
            }
        }

        return null;
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if ($payload === null) {
            throw new AdapterException('Invalid JSON payload from WhatsApp');
        }

        // Navigate the webhook envelope: entry[].changes[].value
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];
                $contacts = $value['contacts'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? $this->phoneNumberId;

                foreach ($messages as $msg) {
                    if (isset($msg['reaction']) || ($msg['type'] ?? '') === 'reaction') {
                        continue;
                    }

                    return $this->parseInboundMessage($msg, $contacts[0] ?? null, $phoneNumberId, $body);
                }
            }
        }

        throw new AdapterException('No message found in WhatsApp webhook payload');
    }

    public function encodeThreadId(mixed $platformData): string
    {
        $phoneNumberId = $platformData['phoneNumberId'] ?? $this->phoneNumberId;
        $userWaId = $platformData['userWaId'] ?? '';

        return "whatsapp:{$phoneNumberId}:{$userWaId}";
    }

    public function decodeThreadId(string $threadId): mixed
    {
        $parts = explode(':', $threadId, 3);

        return [
            'phoneNumberId' => $parts[1] ?? $this->phoneNumberId,
            'userWaId' => $parts[2] ?? '',
        ];
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        return $this->decodeThreadId($threadId)['userWaId'];
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);

        // Convert files to attachments via the registered converter
        if ($message->files !== []) {
            $converted = [];
            foreach ($message->files as $file) {
                $converted[] = $this->fileUploadConverter->upload($file, $this);
            }
            $message = new PostableMessage(
                content: $message->content,
                replyToMessageId: $message->replyToMessageId,
                attachments: array_merge($message->attachments, $converted),
            );
        }

        // Attachments take priority
        if ($message->attachments !== []) {
            $att = $message->attachments[0];
            $text = $message->getTextContent();
            $params = [
                'messaging_product' => 'whatsapp',
                'type' => match ($att->type) {
                    'image' => 'image',
                    'video' => 'video',
                    'audio' => 'audio',
                    default => 'document',
                },
            ];

            $mediaField = $params['type'];
            $params[$mediaField] = ['link' => $att->url];
            if ($att->name !== null && $mediaField === 'document') {
                $params[$mediaField]['filename'] = $att->name;
            }
            if ($text !== '') {
                $params[$mediaField]['caption'] = $text;
            }

            $params = $this->addRecipientParam($params, $decoded['userWaId']);
            $response = $this->apiCall("/{$this->phoneNumberId}/messages", $params);

            $msgId = $response['messages'][0]['id'] ?? uniqid('wa_', true);

            return new SentMessage(id: $msgId, threadId: $threadId);
        }

        $params = $this->buildMessageParams($message);
        $params = $this->addRecipientParam($params, $decoded['userWaId']);
        $params['messaging_product'] = 'whatsapp';

        $response = $this->apiCall("/{$this->phoneNumberId}/messages", $params);

        $msgId = $response['messages'][0]['id'] ?? uniqid('wa_', true);

        return new SentMessage(
            id: $msgId,
            threadId: $threadId,
        );
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        // WhatsApp doesn't support editing messages — resend
        return $this->postMessage($threadId, $message);
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $params = $this->addRecipientParam([
            'messaging_product' => 'whatsapp',
            'message_id' => $messageId,
            'status' => 'read', // Mark as read (closest to "delete" in WhatsApp)
        ], $decoded['userWaId']);
        $this->apiCall("/{$this->phoneNumberId}/messages", $params);
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $params = $this->addRecipientParam([
            'messaging_product' => 'whatsapp',
            'type' => 'reaction',
            'reaction' => [
                'message_id' => $messageId,
                'emoji' => $emoji,
            ],
        ], $decoded['userWaId']);
        $this->apiCall("/{$this->phoneNumberId}/messages", $params);
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $params = $this->addRecipientParam([
            'messaging_product' => 'whatsapp',
            'type' => 'reaction',
            'reaction' => [
                'message_id' => $messageId,
                'emoji' => '',
            ],
        ], $decoded['userWaId']);
        $this->apiCall("/{$this->phoneNumberId}/messages", $params);
    }

    public function startTyping(string $threadId): void
    {
        if (! $this->state instanceof StateAdapter) {
            return;
        }

        $messageId = $this->state->get("typing_msg:{$threadId}");

        if (! is_string($messageId) || $messageId === '') {
            return;
        }

        $decoded = $this->decodeThreadId($threadId);
        $params = $this->addRecipientParam([
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
            'typing_indicator' => [
                'type' => 'text',
            ],
        ], $decoded['userWaId']);
        $this->apiCall("/{$this->phoneNumberId}/messages", $params);
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        return new FetchResult(messages: []);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        $decoded = $this->decodeThreadId($threadId);

        return new ThreadInfo(
            id: $threadId,
            channelId: $decoded['phoneNumberId'],
        );
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        return null;
    }

    public function getUser(string $userId): ?UserInfo
    {
        return new UserInfo(
            id: $userId,
            name: $userId,
        );
    }

    public function openDM(string $userId): ?string
    {
        // WhatsApp is always 1:1 — just return the encoded thread ID
        return $this->encodeThreadId(['userWaId' => $userId]);
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        $this->state = $chat->state;
        $this->botUserId = $this->phoneNumberId;
    }

    public function disconnect(): void
    {
        // No persistent connection
    }

    public function createResponse(): ?ResponseInterface
    {
        return null;
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        return $this->postMessage($threadId, PostableMessage::text($fullText));
    }

    protected function parseInboundMessage(array $msg, ?array $contact, string $phoneNumberId, string $rawBody): Message
    {
        $text = $msg['text']['body']
            ?? $msg['caption']
            ?? $msg['button']['text']
            ?? $msg['interactive']['button_reply']['title']
            ?? '';

        $userWaId = $msg['from'] ?? $msg['from_user_id'] ?? '';
        $msgId = $msg['id'] ?? '';

        $threadId = $this->encodeThreadId([
            'phoneNumberId' => $phoneNumberId,
            'userWaId' => $userWaId,
        ]);

        if ($this->state instanceof StateAdapter && $msgId !== '') {
            $this->state->set("typing_msg:{$threadId}", $msgId, 300_000);
        }

        $contactName = $contact['profile']['name']
            ?? $contact['profile']['username']
            ?? $userWaId;

        return new Message(
            id: $msgId,
            threadId: $threadId,
            author: new Author(
                id: $userWaId,
                name: $contactName,
                isBot: false,
            ),
            text: $text,
            attachments: $this->extractAttachments($msg),
            isDM: true,
            raw: $rawBody,
        );
    }

    /** @return Attachment[] */
    protected function extractAttachments(array $msg): array
    {
        $attachments = [];

        if (isset($msg['image'])) {
            $attachments[] = new Attachment(
                type: 'image',
                name: $msg['image']['caption'] ?? null,
                mimeType: $msg['image']['mime_type'] ?? null,
                fetchMetadata: ['media_id' => $msg['image']['id']],
            );
        }

        if (isset($msg['document'])) {
            $attachments[] = new Attachment(
                type: 'file',
                name: $msg['document']['filename'] ?? null,
                mimeType: $msg['document']['mime_type'] ?? null,
                fetchMetadata: ['media_id' => $msg['document']['id']],
            );
        }

        if (isset($msg['audio'])) {
            $attachments[] = new Attachment(
                type: 'audio',
                mimeType: $msg['audio']['mime_type'] ?? null,
                fetchMetadata: ['media_id' => $msg['audio']['id']],
            );
        }

        if (isset($msg['video'])) {
            $attachments[] = new Attachment(
                type: 'video',
                name: $msg['video']['caption'] ?? null,
                mimeType: $msg['video']['mime_type'] ?? null,
                fetchMetadata: ['media_id' => $msg['video']['id']],
            );
        }

        return $attachments;
    }

    protected function addRecipientParam(array $params, string $userWaId): array
    {
        if (preg_match('/^[A-Z]{2}\./', $userWaId)) {
            $params['recipient'] = $userWaId;
        } else {
            $params['to'] = $userWaId;
        }

        return $params;
    }

    protected function buildMessageParams(PostableMessage $message): array
    {
        if ($message->isTemplate()) {
            $template = $message->content;

            if ($template instanceof WhatsAppTemplate) {
                return $template->toWhatsApp();
            }

            throw new AdapterException('Unsupported template type for WhatsApp adapter');
        }

        if ($message->isCard()) {
            $interactive = WhatsAppCards::toInteractiveMessage($message->content);

            if ($interactive !== null) {
                return [
                    'type' => 'interactive',
                    'interactive' => $interactive,
                ];
            }

            return [
                'type' => 'text',
                'text' => ['body' => WhatsAppCards::cardToText($message->content)],
            ];
        }

        $content = (string) $message->content;

        return [
            'type' => 'text',
            'text' => ['body' => $content],
        ];
    }

    protected function apiCall(string $endpoint, array $params): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;
        $url = "{$this->apiUrl}{$endpoint}";

        $body = json_encode(array_filter($params, fn ($v): bool => $v !== null));
        $request = $factory->createRequest('POST', $url)
            ->withHeader('Authorization', "Bearer {$this->accessToken}")
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream($body));

        $psrResponse = $this->httpClient->sendRequest($request);
        $responseBody = (string) $psrResponse->getBody();

        $data = json_decode($responseBody, true);

        if (! is_array($data)) {
            throw new AdapterException("Invalid JSON response from WhatsApp API: {$endpoint}");
        }

        if (isset($data['error'])) {
            $error = $data['error']['message'] ?? ($data['error']['code'] ?? 'unknown_error');
            $code = $data['error']['code'] ?? 0;

            if (in_array($code, [401, 403], true)) {
                throw new AuthenticationException("WhatsApp API authentication error ({$endpoint}): {$error}");
            }

            throw new AdapterException("WhatsApp API error ({$endpoint}): {$error}");
        }

        return $data;
    }
}
