---
providerId: sinch
channel: sms
novuProviderId: null
auth:
  method: bearer
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://us.sms.api.sinch.com/xms/v1/{servicePlanId}/batches
  regional:
    - us
    - eu
versioning:
  vendorApiVersion: "v1"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://developers.sinch.com/docs/sms/api-reference/sms/tag/Batches/
retryAfterSurfaced: true
notes_passthrough: |
  Forward Sinch-specific fields (e.g. `delivery_report`, `flash_message`,
  `feedback_enabled`) via `_passthrough.body`.
---

# Sinch Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Sinch` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Sinch\SinchConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new SinchConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Sinch, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`SinchConfig(apiToken: string, servicePlanId: string, region: ?SinchRegion = null)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

API token from Sinch dashboard. Sent as `Authorization: Bearer <token>`. Regional clusters available.

## Endpoint

`https://us.sms.api.sinch.com/xms/v1/{servicePlanId}/batches`

## Regional endpoints

`us`, `eu`

## Rate limits

See vendor docs: <https://developers.sinch.com/docs/sms/api-reference/sms/tag/Batches/>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Sinch-specific fields (e.g. `delivery_report`, `flash_message`, `feedback_enabled`) via `_passthrough.body`.
