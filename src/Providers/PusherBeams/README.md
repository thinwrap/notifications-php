# Pusher Beams Push Connector (PHP)

---
providerId: pusher-beams
channel: push
novuProviderId: pusher-beams
auth:
  method: header
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://{instanceId}.pushnotifications.pusher.com/publish_api/v1/instances/{instanceId}/publishes
versioning:
  vendorApiVersion: "v1"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://pusher.com/docs/beams/reference/publish-api/
retryAfterSurfaced: true
notes_passthrough: |
  Forward Pusher Beams fields. **Outlier**: at the wire layer the
  connector synthesizes both `apns.aps` and `fcm.notification` payloads
  from the normalized input so a single send fans out across both
  platforms.
---

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::PusherBeams` is passed to the `Push` facade.

```php
use Thinwrap\Notifications\Push;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\PusherBeams\PusherBeamsConfig;
use Thinwrap\Notifications\DTO\Push\PushSendInput;

$cfg = new PusherBeamsConfig(/* see config shape below */);
$push = new Push(NotificationProviderId::PusherBeams, $cfg);
$result = $push->send(new PushSendInput(to: 'device-token', title: 'Hello', body: 'Hello, world!'));
```

## Configuration

`PusherBeamsConfig(instanceId: string, primaryKey: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Instance ID + Primary Key from Pusher Beams dashboard. Sent as `Authorization: Bearer <primaryKey>`.

## Endpoint

`https://{instanceId}.pushnotifications.pusher.com/publish_api/v1/instances/{instanceId}/publishes`

## Rate limits

See vendor docs: <https://pusher.com/docs/beams/reference/publish-api/>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Pusher Beams fields. **Outlier**: at the wire layer the connector synthesizes both `apns.aps` and `fcm.notification` payloads from the normalized input so a single send fans out across both platforms.
