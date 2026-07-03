# WhatsApp Business Chat Connector (PHP)

---
providerId: whatsapp-business
channel: chat
novuProviderId: whatsapp-business
auth:
  method: bearer
  tokenLifecycle: opaque-rotates
  tokenCacheHookSupported: false
endpoint:
  default: https://graph.facebook.com/v18.0/{phoneNumberId}/messages
versioning:
  vendorApiVersion: "v18.0"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://developers.facebook.com/docs/whatsapp/cloud-api/overview#rate-limits
retryAfterSurfaced: true
notes_passthrough: |
  Forward Meta Cloud API-specific fields (e.g. `template`, `interactive`,
  `context`) via `_passthrough.body`.
---

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::WhatsappBusiness` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\WhatsappBusiness\WhatsappBusinessConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new WhatsappBusinessConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::WhatsappBusiness, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`WhatsappBusinessConfig(accessToken: string, phoneNumberId: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

System-user access token from Meta Business → System Users. Sent as `Authorization: Bearer <token>`. Phone Number ID identifies the sending number.

## Endpoint

`https://graph.facebook.com/v18.0/{phoneNumberId}/messages`

## Rate limits

See vendor docs: <https://developers.facebook.com/docs/whatsapp/cloud-api/overview#rate-limits>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Meta Cloud API-specific fields (e.g. `template`, `interactive`, `context`) via `_passthrough.body`.
