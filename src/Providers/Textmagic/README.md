# TextMagic Sms Connector (PHP)

---
providerId: textmagic
channel: sms
novuProviderId: null
auth:
  method: header
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://rest.textmagic.com/api/v2/messages
versioning:
  vendorApiVersion: "v2"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://docs.textmagic.com/
retryAfterSurfaced: true
notes_passthrough: |
  Forward TextMagic-specific fields (e.g. `sendingTime`, `cutExtra`,
  `partsCount`) via `_passthrough.body`.
---

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Textmagic` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Textmagic\TextmagicConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new TextmagicConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Textmagic, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`TextmagicConfig(username: string, apiKey: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Username + API v2 key from TextMagic dashboard. Sent as `X-TM-Username` + `X-TM-Key` headers.

## Endpoint

`https://rest.textmagic.com/api/v2/messages`

## Rate limits

See vendor docs: <https://docs.textmagic.com/>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward TextMagic-specific fields (e.g. `sendingTime`, `cutExtra`, `partsCount`) via `_passthrough.body`.
