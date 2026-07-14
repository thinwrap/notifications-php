---
providerId: vonage
channel: sms
novuProviderId: nexmo
auth:
  method: basic
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://rest.nexmo.com/sms/json
versioning:
  vendorApiVersion: "v1"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://developer.vonage.com/messaging/sms/overview
retryAfterSurfaced: true
notes_passthrough: |
  Forward Vonage-specific form fields (e.g. `type`, `client-ref`,
  `callback`) via `_passthrough.body`.
---

# Vonage Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Vonage` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Vonage\VonageConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new VonageConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Vonage, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`VonageConfig(apiKey: string, apiSecret: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

API key + secret from Vonage dashboard. Form-encoded `api_key=...&api_secret=...`. Novu enum value is `nexmo` (legacy rebrand divergence).

## Endpoint

`https://rest.nexmo.com/sms/json`

## Rate limits

See vendor docs: <https://developer.vonage.com/messaging/sms/overview>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Vonage-specific form fields (e.g. `type`, `client-ref`, `callback`) via `_passthrough.body`.
