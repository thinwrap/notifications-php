# Apple Push Notification service Push Connector (PHP)

---
providerId: apns
channel: push
novuProviderId: apns
auth:
  method: jwt-es256
  tokenLifecycle: jwt-signed
  tokenCacheHookSupported: true
endpoint:
  default: https://api.push.apple.com/3/device/{deviceToken}
  regional:
    - production
    - sandbox
versioning:
  vendorApiVersion: "v3 (HTTP/2)"
  lastVerified: 2026-05-18
tier: 2
rateLimitDocsUrl: https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns
retryAfterSurfaced: true
notes_passthrough: |
  Forward APNs-specific fields (e.g. `aps.thread-id`,
  `aps.relevance-score`, custom headers) via `_passthrough.body` and
  `_passthrough.headers`.
---

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Apns` is passed to the `Push` facade.

```php
use Thinwrap\Notifications\Push;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Apns\ApnsConfig;
use Thinwrap\Notifications\DTO\Push\PushSendInput;

$cfg = new ApnsConfig(/* see config shape below */);
$push = new Push(NotificationProviderId::Apns, $cfg);
$result = $push->send(new PushSendInput(to: 'device-token', title: 'Hello', body: 'Hello, world!'));
```

## Configuration

`ApnsConfig(teamId: string, keyId: string, privateKeyPem: string, bundleId: string, sandbox: bool = false, tokenCache: ?TokenCacheHook = null)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Auth Key (`.p8` file) + Key ID + Team ID from Apple Developer portal. JWT ES256 signed locally with the EC private key. Sandbox endpoint at `api.sandbox.push.apple.com`. **Stateless by default** — the wrapper signs a fresh JWT per send. Supply an optional `TokenCacheHook` on `ApnsConfig` to amortize signing cost. Cache key shape: `apns:<teamId>:<keyId>:<bundleId>`. Epoch is **seconds** — divergent from TypeScript sibling's ms.

### Optional `TokenCacheHook`

This connector signs a fresh JWT on every send by default (stateless — the wrapper holds no state). Consumers that want to amortize the signing cost supply a `Thinwrap\Notifications\Contract\TokenCacheHook` on the config. The hook owns the state — the wrapper calls `get` / `set`. Epoch is **seconds** (PHP `time()`) — divergent from the TypeScript sibling's milliseconds.

## Endpoint

`https://api.push.apple.com/3/device/{deviceToken}`

## Regional endpoints

`production`, `sandbox`

## Rate limits

See vendor docs: <https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 2** (auth-heavy: JWT signing or AWS SigV4).

## `_passthrough` example

Forward APNs-specific fields (e.g. `aps.thread-id`, `aps.relevance-score`, custom headers) via `_passthrough.body` and `_passthrough.headers`.
