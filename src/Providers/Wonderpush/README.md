---
providerId: wonderpush
channel: push
novuProviderId: null
auth:
  method: basic
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://management-api.wonderpush.com/v1/deliveries
versioning:
  vendorApiVersion: "v1"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://www.wonderpush.com/docs/reference/management-api/rate-limits
retryAfterSurfaced: true
notes_passthrough: |
  Forward WonderPush-specific fields (e.g. `targetSegmentIds`,
  `notification`, `actions`) via `_passthrough.body`.
---

# WonderPush Push Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Wonderpush` is passed to the `Push` facade.

```php
use Thinwrap\Notifications\Push;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Wonderpush\WonderpushConfig;
use Thinwrap\Notifications\DTO\Push\PushSendInput;

$cfg = new WonderpushConfig(/* see config shape below */);
$push = new Push(NotificationProviderId::Wonderpush, $cfg);
$result = $push->send(new PushSendInput(to: 'device-token', title: 'Hello', body: 'Hello, world!'));
```

## Configuration

`WonderpushConfig(applicationId: string, accessToken: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Application ID + Access Token from WonderPush dashboard. HTTP Basic auth as `<applicationId>:<accessToken>`.

## Endpoint

`https://management-api.wonderpush.com/v1/deliveries`

## Rate limits

See vendor docs: <https://www.wonderpush.com/docs/reference/management-api/rate-limits>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward WonderPush-specific fields (e.g. `targetSegmentIds`, `notification`, `actions`) via `_passthrough.body`.
