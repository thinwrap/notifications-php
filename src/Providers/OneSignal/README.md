---
providerId: one-signal
channel: push
novuProviderId: one-signal
auth:
  method: header
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://onesignal.com/api/v1/notifications
versioning:
  vendorApiVersion: "v1"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://documentation.onesignal.com/reference/rate-limits
retryAfterSurfaced: true
notes_passthrough: |
  Forward OneSignal-specific fields (e.g. `filters`, `included_segments`,
  `send_after`) via `_passthrough.body`. Invalid recipient lists return
  `success: false` with `status: Rejected` (soft-reject, not exception).
---

# OneSignal Push Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::OneSignal` is passed to the `Push` facade.

```php
use Thinwrap\Notifications\Push;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\OneSignal\OneSignalConfig;
use Thinwrap\Notifications\DTO\Push\PushSendInput;

$cfg = new OneSignalConfig(/* see config shape below */);
$push = new Push(NotificationProviderId::OneSignal, $cfg);
$result = $push->send(new PushSendInput(to: 'device-token', title: 'Hello', body: 'Hello, world!'));
```

## Configuration

`OneSignalConfig(appId: string, restApiKey: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

REST API Key from OneSignal dashboard → Settings → Keys & IDs. Sent as `Authorization: Basic <key>` header (vendor uses `Basic` despite single-token).

## Endpoint

`https://onesignal.com/api/v1/notifications`

## Rate limits

See vendor docs: <https://documentation.onesignal.com/reference/rate-limits>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward OneSignal-specific fields (e.g. `filters`, `included_segments`, `send_after`) via `_passthrough.body`. Invalid recipient lists return `success: false` with `status: Rejected` (soft-reject, not exception).
