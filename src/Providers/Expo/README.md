---
providerId: expo
channel: push
novuProviderId: expo
auth:
  method: bearer
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://exp.host/--/api/v2/push/send
versioning:
  vendorApiVersion: "v2"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://docs.expo.dev/push-notifications/sending-notifications/#rate-limits
retryAfterSurfaced: true
notes_passthrough: |
  Forward Expo-specific fields (e.g. `categoryId`, `mutableContent`,
  `channelId`) via `_passthrough.body`.
---

# Expo Push Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Expo` is passed to the `Push` facade.

```php
use Thinwrap\Notifications\Push;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Expo\ExpoConfig;
use Thinwrap\Notifications\DTO\Push\PushSendInput;

$cfg = new ExpoConfig(/* see config shape below */);
$push = new Push(NotificationProviderId::Expo, $cfg);
$result = $push->send(new PushSendInput(to: 'device-token', title: 'Hello', body: 'Hello, world!'));
```

## Configuration

`ExpoConfig(accessToken: ?string = null)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Optional access token from Expo dashboard (required for enhanced security on paid plans). Sent as `Authorization: Bearer <token>`.

## Endpoint

`https://exp.host/--/api/v2/push/send`

## Rate limits

See vendor docs: <https://docs.expo.dev/push-notifications/sending-notifications/#rate-limits>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Expo-specific fields (e.g. `categoryId`, `mutableContent`, `channelId`) via `_passthrough.body`.
