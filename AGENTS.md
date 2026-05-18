# adapter-whatsapp

WhatsApp Cloud API adapter for bootdesk/chat-sdk-core. Namespace: `BootDesk\ChatSDK\WhatsApp`

## files
- `WhatsAppAdapter` — implements `Adapter` using WhatsApp Business Cloud API
- `WhatsAppFormatConverter` — WhatsApp text ↔ CommonMark AST
- `WhatsAppCards` — Card model → WhatsApp Interactive Reply buttons / List sections
- `WhatsAppTemplate` — WhatsApp Message Template builder (for template messages)
- `WhatsAppWebhookVerifier` — verifies webhook signature + verify_token challenge

## registration
`src/register.php` registers `'whatsapp' => WhatsAppAdapter::class` via `AdapterRegistry`

## constructor
```php
new WhatsAppAdapter(
    string $accessToken,
    string $appSecret,
    string $phoneNumberId,
    string $verifyToken,
    ClientInterface $httpClient,
    ?Psr17Factory $psrFactory = null,
);
```

## thread ID format
`whatsapp:{phoneNumberId}:{fromNumber}` — per-user thread based on sender's phone number

## contracts implemented
- `HandlesReactions` — `parseReaction()` for reaction webhook events
- `HandlesStatuses` — `parseStatus()` for message delivered/read statuses
- `AdapterHasMessagingWindow` — 24h messaging window with per-user tracking key

## webhook flow
1. `verifyWebhook` — responds to `hub.verify_token` challenge; verifies request signature
2. `parseWebhook` — extracts text, interactive replies, button responses, template sends

## features
- Send text, interactive buttons, lists, templates
- Mark messages as read
- Typing indicators (not natively supported — sends read receipts instead)
- Fetch messages from webhook payload only (no history API)
- Streaming: concatenates and sends as single message

## config (laravel)
```php
'whatsapp' => [
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
],
```
