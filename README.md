# bootdesk/chat-sdk-adapter-whatsapp

WhatsApp Business API adapter for the laravel-bootdesk multi-platform messaging framework.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-whatsapp
```

Requires a PSR-18 HTTP client (`guzzlehttp/guzzle`, `symfony/http-client`, etc.) and a PSR-17 factory (`nyholm/psr7` bundled).

## Configuration

| Variable | Description | Example |
|----------|-------------|---------|
| `access_token` | WhatsApp Cloud API Access Token | `EAAx...` |
| `http_client` | PSR-18 HTTP client instance | `new GuzzleHttp\Client` |
| `phone_number_id` | Phone Number ID | `9876543210` |
| `app_secret` | Meta App Secret | `abc123...` |
| `verify_token` | Webhook Verify Token | `my-verify-token` |

```php
use BootDesk\ChatSDK\WhatsApp\WhatsAppAdapter;

$adapter = new WhatsAppAdapter(
    accessToken: env('WHATSAPP_ACCESS_TOKEN'),
    httpClient: new \GuzzleHttp\Client,
    phoneNumberId: env('WHATSAPP_PHONE_NUMBER_ID'),
    appSecret: env('WHATSAPP_APP_SECRET'),
    verifyToken: env('WHATSAPP_VERIFY_TOKEN'),
);
```

### Laravel

The `ChatServiceProvider` auto-binds `Psr\Http\Client\ClientInterface` to `GuzzleHttp\Client`. Add to `config/chat.php`:

```php
'whatsapp' => [
    'access_token'    => env('WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'app_secret'      => env('WHATSAPP_APP_SECRET'),
    'verify_token'    => env('WHATSAPP_VERIFY_TOKEN'),
],
```

## Quick Example

```php
// Send a message to a phone number
$adapter->postMessage('whatsapp:+15551234567', 'Hello from laravel-bootdesk!');
```

## Template Messages

WhatsApp supports pre-approved message templates for notifications, alerts, and marketing. Send them via `PostableMessage::template()`:

```php
use BootDesk\ChatSDK\WhatsApp\WhatsAppTemplate;

$adapter->postMessage(
    'whatsapp:phone123:5511999999999',
    PostableMessage::template(
        WhatsAppTemplate::create('order_confirmation', 'en_US')
            ->bodyParam('John')
            ->bodyParam('#12345')
            ->buttonParam('Track Order', 'track_payload')
    )
);
```

### WhatsAppTemplate API

| Method | Description |
|--------|-------------|
| `create(string $name, string $language)` | Factory with template name and language code |
| `positional()` | Use positional `{{1}}`, `{{2}}` format (default) |
| `named()` | Use named `{{first_name}}` format; `bodyParam()` then takes `(text, parameterName)` |
| `bodyParam(string $text, ?string $parameterName = null)` | Adds a body parameter; pass `parameterName` for named format |
| `headerImage(string $link)` | Header image media |
| `headerVideo(string $link)` | Header video media |
| `headerDocument(string $link, ?string $filename)` | Header document media |
| `headerText(string $text)` | Header text parameter |
| `buttonParam(string $label, string $payload = '', string $subtype = 'quick_reply')` | Button parameter; `payload` defaults to `label` if omitted |

Serializes to WhatsApp API format:

```json
{
  "type": "template",
  "template": {
    "name": "order_confirmation",
    "language": {"code": "en_US"},
    "components": [
      {"type": "body", "parameters": [
        {"type": "text", "text": "John"},
        {"type": "text", "text": "#12345"}
      ]},
      {"type": "button", "sub_type": "quick_reply", "index": 0, "parameters": [
        {"type": "quick_reply", "payload": "track_payload"}
      ]}
    ]
  }
}
```

### Named parameters

Templates using `{{first_name}}` instead of `{{1}}`:

```php
WhatsAppTemplate::create('order_confirmation', 'en_US')
    ->named()
    ->bodyParam('Jessica', 'first_name')
    ->bodyParam('SKBUP2-4CPIG9', 'order_number');
```

Sends `parameter_name` on each parameter and `parameter_format: "named"` at the template level. Omit `->named()` (or call `->positional()`) for positional `{{1}}` format.

Falls back to markdown text via `__toString()` when an adapter doesn't support templates.

## Thread ID Format

| Format | Description |
|--------|-------------|
| `whatsapp:{phoneNumberId}` | One thread per phone number (missing user ID uses fallback) |
| `whatsapp:{phoneNumberId}:{userPhoneNumber}` | Thread with known phone number |
| `whatsapp:{phoneNumberId}:{bsuid}` | Thread identified by Business-Scoped User ID |

## Webhook

WhatsApp Cloud API sends webhook payloads. Verify requests using HMAC signature verification with the app secret.

### BSUID (Business-Scoped User ID) Support

As of May 2026, WhatsApp supports BSUIDs — a user identifier independent of phone numbers. The adapter transparently handles both identifiers:

- **Inbound**: reads `from` (phone) with fallback to `from_user_id` (BSUID)
- **Outbound**: detects BSUID format (`XX.xxxx`) and uses `recipient` instead of `to`
- **Thread IDs**: use whatever identifier was available in the webhook

## Feature Matrix

| Feature | Supported |
|---------|-----------|
| Post messages | ✓ |
| Edit messages | ✗ |
| Delete messages | ✗ |
| Reactions | ✓ |
| Typing indicator | ✓ |
| Fetch messages | ✓ |
| Fetch thread info | ✗ |
| Fetch channel info | ✗ |
| Get user | ✗ |
| Open DM | ✗ |
| Stream | ✓ |

## Notes

WhatsApp Business API only. No edit or delete support in the WhatsApp Cloud API.

## Documentationn
Full API documentation: https://bootdesk.github.io/chat-sdk

## License

MIT
