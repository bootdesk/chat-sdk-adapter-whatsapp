<?php

namespace BootDesk\ChatSDK\WhatsApp;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\AdapterHasMessagingWindow;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\HandlesBatchedWebhooks;
use BootDesk\ChatSDK\Core\Contracts\HandlesMessageCosts;
use BootDesk\ChatSDK\Core\Contracts\HandlesReactions;
use BootDesk\ChatSDK\Core\Contracts\HandlesSlashCommands;
use BootDesk\ChatSDK\Core\Contracts\HandlesStatuses;
use BootDesk\ChatSDK\Core\Contracts\HasAuthorInfo;
use BootDesk\ChatSDK\Core\Contracts\MustRehydrateAttachments;
use BootDesk\ChatSDK\Core\Contracts\RequiresAsyncResponse;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\Exceptions\UnsupportedOperationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\LocalizationType;
use BootDesk\ChatSDK\Core\LocalizationValue;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\EmojiResolver;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use BootDesk\ChatSDK\Core\WebhookEvent;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WhatsAppAdapter implements Adapter, AdapterHasMessagingWindow, HandlesBatchedWebhooks, HandlesMessageCosts, HandlesReactions, HandlesSlashCommands, HandlesStatuses, HasAuthorInfo, MustRehydrateAttachments, RequiresAsyncResponse
{
    protected ?string $botUserId = null;

    protected WhatsAppFormatConverter $formatConverter;

    protected ?WhatsAppWebhookVerifier $webhookVerifier = null;

    protected ?StateAdapter $state = null;

    protected FileUploadConverter $fileUploadConverter;

    protected EmojiResolver $emojiResolver;

    protected readonly ?LoggerInterface $logger;

    public function __construct(
        protected readonly string $accessToken,
        protected readonly ClientInterface $httpClient,
        protected readonly string $phoneNumberId,
        ?string $appSecret = null,
        ?string $verifyToken = null,
        protected readonly string $apiUrl = 'https://graph.facebook.com/v21.0',
        protected readonly ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
        ?EmojiResolver $emojiResolver = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
        $this->formatConverter = new WhatsAppFormatConverter;
        $this->fileUploadConverter = $fileUploadConverter ?? new NullFileUploadConverter;
        $this->emojiResolver = $emojiResolver ?? EmojiResolver::default();

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
                $contacts = $value['contacts'] ?? [];
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

                    $author = $this->createAuthor($msg['from'], $contacts[0] ?? null);

                    return [
                        'author' => $author,
                        'emoji' => $this->emojiResolver->fromGChat($rawEmoji),
                        'rawEmoji' => $rawEmoji,
                        'added' => $added,
                        'threadId' => $threadId,
                        'messageId' => $reaction['message_id'],
                        'userId' => $msg['from'],
                        'raw' => $payload,
                        'originId' => null,
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
                        'originId' => null,
                    ];
                }
            }
        }

        return null;
    }

    public function parseMessageCost(ServerRequestInterface $request): ?array
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
                    $pricing = $status['pricing'] ?? null;
                    if ($pricing === null) {
                        continue;
                    }

                    $recipientId = $status['recipient_id'] ?? '';
                    $threadId = $this->encodeThreadId([
                        'phoneNumberId' => $phoneNumberId,
                        'userWaId' => $recipientId,
                    ]);

                    return [
                        'messageIds' => [$status['id'] ?? ''],
                        'threadId' => $threadId,
                        'userId' => $recipientId,
                        'price' => null,
                        'raw' => [
                            'pricing' => $pricing,
                            'status' => $status['status'] ?? null,
                            'timestamp' => $status['timestamp'] ?? null,
                        ],
                        'originId' => $entry['id'] ?? null,
                    ];
                }
            }
        }

        return null;
    }

    public function parseSlashCommand(ServerRequestInterface $request): ?array
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
                $contacts = $value['contacts'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? $this->phoneNumberId;

                foreach ($messages as $msg) {
                    $rawText = $msg['text']['body'] ?? '';

                    if ($rawText === '' || $rawText[0] !== '/') {
                        continue;
                    }

                    $parts = explode(' ', $rawText, 2);
                    $command = $parts[0];
                    $text = $parts[1] ?? '';

                    $threadId = $this->encodeThreadId([
                        'phoneNumberId' => $phoneNumberId,
                        'userWaId' => $msg['from'],
                    ]);

                    $author = $this->createAuthor($msg['from'], $contacts[0] ?? null);

                    return [
                        'author' => $author,
                        'command' => $command,
                        'text' => $text,
                        'userId' => $msg['from'],
                        'isBot' => false,
                        'isMe' => false,
                        'channelId' => $threadId,
                        'triggerId' => null,
                        'raw' => $body,
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
            $this->logger->error('[WhatsApp] Invalid JSON payload from webhook');
            throw new AdapterException('Invalid JSON payload from WhatsApp');
        }

        // Navigate the webhook envelope: entry[].changes[].value
        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
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

                    // Skip interactive messages (button_reply/list_reply) — handled as actions via batched path
                    if (($msg['type'] ?? '') === 'interactive') {
                        continue;
                    }

                    $message = $this->parseInboundMessage($msg, $contacts[0] ?? null, $phoneNumberId, $body, $originId);

                    $this->logger->info('[WhatsApp] Message parsed', [
                        'from' => $message->author->id,
                        'text_preview' => mb_substr($message->text, 0, 100),
                    ]);

                    return $message;
                }
            }
        }

        throw new UnsupportedOperationException('No message found in WhatsApp webhook payload');
    }

    public function parseBatchedWebhook(ServerRequestInterface $request): array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return [];
        }

        $events = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;

            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? '';

                if ($field !== 'messages') {
                    $events[] = new WebhookEvent(
                        type: WebhookEvent::TYPE_UNSUPPORTED,
                        threadId: '',
                        payload: $change,
                        originId: $originId,
                    );

                    continue;
                }

                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? $this->phoneNumberId;
                $contacts = $value['contacts'] ?? [];

                // Process user messages (non-reaction)
                foreach ($value['messages'] ?? [] as $msg) {
                    if (isset($msg['reaction']) || ($msg['type'] ?? '') === 'reaction') {
                        $reaction = $msg['reaction'] ?? [];
                        $rawEmoji = $reaction['emoji'] ?? '';
                        $threadId = $this->encodeThreadId([
                            'phoneNumberId' => $phoneNumberId,
                            'userWaId' => $msg['from'],
                        ]);

                        $author = $this->createAuthor($msg['from'], $contacts[0] ?? null);

                        $events[] = new WebhookEvent(
                            type: WebhookEvent::TYPE_REACTION,
                            threadId: $threadId,
                            payload: [
                                'author' => $author,
                                'emoji' => $this->emojiResolver->fromGChat($rawEmoji),
                                'rawEmoji' => $rawEmoji,
                                'added' => $rawEmoji !== '',
                                'messageId' => $reaction['message_id'],
                                'userId' => $msg['from'],
                                'raw' => $payload,
                            ],
                            originId: $originId,
                        );
                    } else {
                        $threadId = $this->encodeThreadId([
                            'phoneNumberId' => $phoneNumberId,
                            'userWaId' => $msg['from'],
                        ]);

                        $rawText = $msg['text']['body'] ?? '';

                        // Check if this is an interactive button/list reply (action)
                        $msgType = $msg['type'] ?? '';
                        if ($msgType === 'interactive') {
                            $interactiveType = $msg['interactive']['type'] ?? '';
                            if ($interactiveType === 'button_reply' || $interactiveType === 'list_reply') {
                                $replyData = $msg['interactive'][$interactiveType] ?? [];
                                $callbackId = $replyData['id'] ?? '';
                                $decoded = WhatsAppCards::decodeCallbackData($callbackId);

                                $author = $this->createAuthor($msg['from'], $contacts[0] ?? null);

                                $events[] = new WebhookEvent(
                                    type: WebhookEvent::TYPE_ACTION,
                                    threadId: $threadId,
                                    payload: [
                                        'author' => $author,
                                        'actionId' => $decoded['actionId'],
                                        'value' => $decoded['value'],
                                        'messageId' => $msg['context']['id'] ?? $msg['id'] ?? '',
                                        'userId' => $msg['from'],
                                        'isBot' => false,
                                        'isMe' => false,
                                        'triggerId' => null,
                                        'raw' => $payload,
                                        'callbackQueryId' => null,
                                    ],
                                    originId: $originId,
                                );
                            }
                        } elseif ($rawText !== '' && $rawText[0] === '/') {
                            $parts = explode(' ', $rawText, 2);
                            $command = $parts[0];
                            $text = $parts[1] ?? '';

                            $author = $this->createAuthor($msg['from'], $contacts[0] ?? null);

                            $events[] = new WebhookEvent(
                                type: WebhookEvent::TYPE_SLASH_COMMAND,
                                threadId: $threadId,
                                payload: [
                                    'author' => $author,
                                    'command' => $command,
                                    'text' => $text,
                                    'userId' => $msg['from'],
                                    'isBot' => false,
                                    'isMe' => false,
                                    'channelId' => $threadId,
                                    'triggerId' => null,
                                    'raw' => $payload,
                                ],
                                originId: $originId,
                            );
                        } else {
                            $events[] = new WebhookEvent(
                                type: WebhookEvent::TYPE_MESSAGE,
                                threadId: $threadId,
                                payload: $this->parseInboundMessage($msg, $contacts[0] ?? null, $phoneNumberId, $body, $originId),
                                originId: $originId,
                            );
                        }
                    }
                }

                // Process statuses
                foreach ($value['statuses'] ?? [] as $status) {
                    $statusType = $status['status'] ?? '';
                    $recipientId = $status['recipient_id'] ?? '';
                    $threadId = $this->encodeThreadId([
                        'phoneNumberId' => $phoneNumberId,
                        'userWaId' => $recipientId,
                    ]);

                    if (in_array($statusType, ['delivered', 'read', 'failed'], true)) {
                        $events[] = new WebhookEvent(
                            type: WebhookEvent::TYPE_STATUS,
                            threadId: $threadId,
                            payload: [
                                'type' => $statusType,
                                'messageIds' => [$status['id'] ?? ''],
                                'userId' => $recipientId,
                                'timestamp' => (int) ($status['timestamp'] ?? 0),
                                'raw' => $payload,
                            ],
                            originId: $originId,
                        );
                    }

                    // Emit cost event if pricing data is present (independent of status type)
                    $pricing = $status['pricing'] ?? null;
                    if ($pricing !== null) {
                        $events[] = new WebhookEvent(
                            type: WebhookEvent::TYPE_MESSAGE_COST,
                            threadId: $threadId,
                            payload: [
                                'messageIds' => [$status['id'] ?? ''],
                                'userId' => $recipientId,
                                'price' => null,
                                'raw' => [
                                    'pricing' => $pricing,
                                    'status' => $statusType,
                                    'timestamp' => $status['timestamp'] ?? null,
                                ],
                            ],
                            originId: $originId,
                        );
                    }
                }
            }
        }

        return $events;
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
        return $threadId;
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);

        $this->logger->info('[WhatsApp] Posting message', [
            'threadId' => $threadId,
            'has_files' => $message->files !== [] ? 'yes' : 'no',
            'has_attachments' => $message->attachments !== [] ? 'yes' : 'no',
            'is_card' => $message->isCard() ? 'yes' : 'no',
            'text_preview' => mb_substr($message->getTextContent(), 0, 100),
        ]);

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

            // Location attachment — native WhatsApp location message
            if ($att->type === 'location') {
                $text = $this->formatConverter->renderPostable($message);
                $locParams = [
                    'messaging_product' => 'whatsapp',
                    'type' => 'location',
                    'location' => [
                        'longitude' => $att->lng,
                        'latitude' => $att->lat,
                        'name' => $att->name,
                        'address' => $att->address,
                    ],
                ];

                $locParams = $this->addRecipientParam($locParams, $decoded['userWaId']);
                $response = $this->apiCall("/{$this->phoneNumberId}/messages", $locParams);
                $additionalMessages = [];

                // WhatsApp doesn't support caption on location — send text separately
                if ($text !== '') {
                    $textParams = $this->buildMessageParams($message);
                    $textParams = $this->addRecipientParam($textParams, $decoded['userWaId']);
                    $textParams['messaging_product'] = 'whatsapp';
                    $textResponse = $this->apiCall("/{$this->phoneNumberId}/messages", $textParams);
                    $additionalMessages[] = new SentMessage(
                        id: $textResponse['messages'][0]['id'] ?? '',
                        threadId: $threadId,
                    );
                }

                $msgId = $response['messages'][0]['id'] ?? uniqid('wa_', true);

                return new SentMessage(id: $msgId, threadId: $threadId, additionalMessages: $additionalMessages);
            }

            $text = $this->formatConverter->renderPostable($message);
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

        // Card messages — multi-message orchestration
        if ($message->isCard()) {
            return $this->sendCardMessage($threadId, $message);
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

    private function sendCardMessage(string $threadId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);
        $card = $message->content;
        $additionalMessages = [];
        $mainId = null;
        $recipient = $decoded['userWaId'];

        // 1. Send image as separate media message
        $mediaParams = WhatsAppCards::toMediaMessage($card);
        if ($mediaParams !== null) {
            // Use bolded header as caption only — body follows as separate message
            $caption = '';
            if ($card->getHeader() !== null) {
                $caption = '*'.$card->getHeader().'*';
            }
            if ($caption !== '') {
                $mediaParams['image']['caption'] = $caption;
            }

            $mediaParams = $this->addRecipientParam([
                'messaging_product' => 'whatsapp',
                ...$mediaParams,
            ], $recipient);

            try {
                $response = $this->apiCall("/{$this->phoneNumberId}/messages", $mediaParams);
                $mainId = $response['messages'][0]['id'] ?? null;
            } catch (\Exception) {
                // Image send failed — continue with text
            }
        }

        // 2. Build interactive or text params
        $buttons = $card->getButtons();
        $linkButtons = $card->getLinkButtons();

        if ($buttons !== [] && count($buttons) <= 3) {
            // Reply buttons via interactive message (header+buttons excluded from body)
            $interactive = WhatsAppCards::toInteractiveMessage($card);
            $params = $this->addRecipientParam([
                'messaging_product' => 'whatsapp',
                'type' => 'interactive',
                'interactive' => $interactive,
            ], $recipient);
        } elseif ($buttons === [] && count($linkButtons) === 1) {
            // Single link button → CTA URL interactive (body excludes header + interactive)
            $cta = self::buildCtaUrlInteractive($card);
            $params = $this->addRecipientParam([
                'messaging_product' => 'whatsapp',
                'type' => 'interactive',
                'interactive' => $cta,
            ], $recipient);
        } else {
            // Fallback to text (body excludes header, includes links/buttons as inline text)
            $text = WhatsAppCards::cardToText($card, includeHeader: false);
            $params = $this->addRecipientParam([
                'messaging_product' => 'whatsapp',
                'type' => 'text',
                'text' => ['body' => $text],
            ], $recipient);
        }

        $response = $this->apiCall("/{$this->phoneNumberId}/messages", $params);
        $msgId = $response['messages'][0]['id'] ?? uniqid('wa_', true);

        if ($mainId === null) {
            $mainId = $msgId;
        } else {
            $additionalMessages[] = new SentMessage(
                id: $msgId,
                threadId: $threadId,
            );
        }

        return new SentMessage(
            id: $mainId,
            threadId: $threadId,
            additionalMessages: $additionalMessages,
        );
    }

    private function buildCtaUrlInteractive(Card $card): array
    {
        $linkButtons = $card->getLinkButtons();
        $lb = $linkButtons[0];

        $body = WhatsAppCards::cardToText($card, includeHeader: false, excludeInteractive: true);

        return [
            'type' => 'cta_url',
            'body' => ['text' => $body ?: 'Open the link below'],
            'action' => [
                'name' => 'cta_url',
                'parameters' => [
                    'display_text' => mb_substr($lb->label, 0, 20),
                    'url' => $lb->url,
                ],
            ],
        ];
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
                'emoji' => $this->emojiResolver->toGChat($emoji),
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

    public function getAuthorInfo(Author $author): Author
    {
        return $author;
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

    /**
     * @param  array<string, mixed>|null  $contact
     */
    protected function createAuthor(string $userId, ?array $contact): Author
    {
        $localizations = [];
        $name = null;

        if ($contact !== null) {
            if (isset($contact['profile']['language'])) {
                $localizations[] = new LocalizationValue(LocalizationType::Language, $contact['profile']['language']);
            }

            $name = $contact['profile']['name'] ?? $contact['profile']['username'] ?? null;
        }

        return (
            new Author(id: $userId, name: $name)
        )->withLocalizations(...$localizations);
    }

    protected function parseInboundMessage(array $msg, ?array $contact, string $phoneNumberId, string $rawBody, ?string $originId = null): Message
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

        $authorLocalizations = [];
        if (isset($contact['profile']['language'])) {
            $authorLocalizations[] = new LocalizationValue(LocalizationType::Language, $contact['profile']['language']);
        }

        return new Message(
            id: $msgId,
            threadId: $threadId,
            author: new Author(
                ...$authorLocalizations,
                id: $userWaId,
                name: $contactName,
                isBot: false,
            ),
            text: $text,
            attachments: $this->extractAttachments($msg),
            isDM: true,
            raw: $rawBody,
            originId: $originId,
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
                fetchData: [$this, 'fetchMedia'],
                fetchMetadata: ['media_id' => $msg['image']['id']],
            );
        }

        if (isset($msg['document'])) {
            $attachments[] = new Attachment(
                type: 'file',
                name: $msg['document']['filename'] ?? null,
                mimeType: $msg['document']['mime_type'] ?? null,
                fetchData: [$this, 'fetchMedia'],
                fetchMetadata: ['media_id' => $msg['document']['id']],
            );
        }

        if (isset($msg['audio'])) {
            $attachments[] = new Attachment(
                type: 'audio',
                mimeType: $msg['audio']['mime_type'] ?? null,
                fetchData: [$this, 'fetchMedia'],
                fetchMetadata: ['media_id' => $msg['audio']['id']],
            );
        }

        if (isset($msg['video'])) {
            $attachments[] = new Attachment(
                type: 'video',
                name: $msg['video']['caption'] ?? null,
                mimeType: $msg['video']['mime_type'] ?? null,
                fetchData: [$this, 'fetchMedia'],
                fetchMetadata: ['media_id' => $msg['video']['id']],
            );
        }

        if (isset($msg['location'])) {
            $loc = $msg['location'];
            $attachments[] = Attachment::location(
                lat: (float) ($loc['latitude'] ?? 0),
                lng: (float) ($loc['longitude'] ?? 0),
                name: $loc['name'] ?? null,
                address: $loc['address'] ?? null,
            );
        }

        if (isset($msg['sticker'])) {
            $attachments[] = new Attachment(
                type: 'sticker',
                name: 'sticker.webp',
                mimeType: $msg['sticker']['mime_type'] ?? 'image/webp',
                fetchData: [$this, 'fetchMedia'],
                fetchMetadata: ['media_id' => $msg['sticker']['id']],
            );
        }

        if (isset($msg['contacts'])) {
            foreach ($msg['contacts'] as $contact) {
                $name = $contact['name']['formatted_name'] ?? null;
                $phones = $contact['phones'] ?? [];
                $phone = $phones[0]['phone'] ?? null;

                $vcardLines = ['BEGIN:VCARD', 'VERSION:3.0'];
                if ($name !== null) {
                    $vcardLines[] = "FN:{$name}";
                    $first = $contact['name']['first_name'] ?? '';
                    $last = $contact['name']['last_name'] ?? '';
                    if ($first !== '' || $last !== '') {
                        $vcardLines[] = "N:{$last};{$first};;;";
                    }
                }
                foreach ($phones as $p) {
                    $vcardLines[] = 'TEL;TYPE='.strtoupper($p['type'] ?? 'VOICE').':'.$p['phone'];
                }
                foreach ($contact['emails'] ?? [] as $email) {
                    $vcardLines[] = 'EMAIL:'.$email['email'];
                }
                if (isset($contact['org']['company'])) {
                    $vcardLines[] = 'ORG:'.$contact['org']['company'];
                }
                foreach ($contact['urls'] ?? [] as $url) {
                    $vcardLines[] = 'URL:'.$url['url'];
                }
                $vcardLines[] = 'END:VCARD';
                $vcard = implode("\n", $vcardLines);

                $attachments[] = new Attachment(
                    type: 'contact',
                    url: 'data:text/vcard;base64,'.base64_encode($vcard),
                    name: $name,
                    mimeType: 'text/vcard',
                    fetchMetadata: array_filter(['phone' => $phone]),
                );
            }
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
            // Cards are handled in postMessage() via sendCardMessage().
            // This fallback is for direct callers only.
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

    public function fetchMedia(Attachment $attachment): StreamInterface
    {
        $mediaId = $attachment->fetchMetadata['media_id'] ?? null;

        if ($mediaId === null || $mediaId === '') {
            throw new AdapterException('No media_id available for attachment');
        }

        // Step 1: Get media download URL from WhatsApp API
        $data = $this->apiCall("/{$mediaId}", method: 'GET');

        if (! isset($data['url'])) {
            throw new AdapterException('WhatsApp API did not return a media URL');
        }

        // Step 2: Download actual media binary
        $raw = $this->apiCall('', method: 'GET', overrideUrl: $data['url'], returnStream: true);

        return $raw['stream'];
    }

    public function rehydrateAttachment(Attachment $attachment): Attachment
    {
        $mediaId = $attachment->fetchMetadata['media_id'] ?? null;

        if ($mediaId === null || $mediaId === '') {
            return $attachment;
        }

        return $attachment->withFetchOptions(fetchData: [$this, 'fetchMedia'], fetchMetadata: ['media_id' => $mediaId]);
    }

    protected function apiCall(string $endpoint, array $params = [], string $method = 'POST', ?string $overrideUrl = null, bool $returnStream = false): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;
        $url = $overrideUrl ?? "{$this->apiUrl}{$endpoint}";

        $this->logger->debug('[WhatsApp] API call', [
            'endpoint' => $endpoint,
            'method' => $method,
        ]);

        if ($method === 'GET') {
            $request = $factory->createRequest('GET', $url)
                ->withHeader('Authorization', "Bearer {$this->accessToken}");
        } else {
            $body = json_encode(array_filter($params, fn ($v): bool => $v !== null));
            $request = $factory->createRequest('POST', $url)
                ->withHeader('Authorization', "Bearer {$this->accessToken}")
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream($body));
        }

        $psrResponse = $this->httpClient->sendRequest($request);
        $statusCode = $psrResponse->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $responseBody = (string) $psrResponse->getBody();
            $this->logger->error('[WhatsApp] API error', [
                'endpoint' => $endpoint,
                'statusCode' => $statusCode,
                'response' => mb_substr($responseBody, 0, 500),
            ]);
            throw new AdapterException("WhatsApp API returned HTTP {$statusCode}: {$responseBody}");
        }

        if ($returnStream) {
            return ['stream' => $psrResponse->getBody(), 'status' => $statusCode];
        }

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
