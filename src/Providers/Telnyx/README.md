# Telnyx Sms Connector (PHP)

---
providerId: telnyx
channel: sms
novuProviderId: telnyx
auth:
  method: bearer
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://api.telnyx.com/v2/messages
versioning:
  vendorApiVersion: "v2"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://developers.telnyx.com/docs/messaging/messaging-limits
retryAfterSurfaced: true
notes_passthrough: |
  Forward Telnyx-specific fields (e.g. `messaging_profile_id`,
  `webhook_url`, `auto_detect`) via `_passthrough.body`.
---

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Telnyx` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Telnyx\TelnyxConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new TelnyxConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Telnyx, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`TelnyxConfig(apiKey: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

API key from Telnyx Mission Control. Sent as `Authorization: Bearer <key>`.

## Endpoint

`https://api.telnyx.com/v2/messages`

## Rate limits

See vendor docs: <https://developers.telnyx.com/docs/messaging/messaging-limits>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Telnyx-specific fields (e.g. `messaging_profile_id`, `webhook_url`, `auto_detect`) via `_passthrough.body`.
