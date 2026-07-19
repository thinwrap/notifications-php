# Firebase Cloud Messaging Push Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Fcm` is passed to the `Push` facade.

```php
use Thinwrap\Notifications\Push;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Fcm\FcmConfig;
use Thinwrap\Notifications\DTO\Push\PushSendInput;

$cfg = new FcmConfig(/* see config shape below */);
$push = new Push(NotificationProviderId::Fcm, $cfg);
$result = $push->send(new PushSendInput(to: 'device-token', title: 'Hello', body: 'Hello, world!'));
```

## Configuration

`FcmConfig(serviceAccountJson: string, tokenCache: ?TokenCacheHook = null)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Service-account JSON from Firebase Console → Project Settings → Service accounts. JWT RS256 signed locally, exchanged for OAuth2 access token at `https://oauth2.googleapis.com/token`. **Stateless by default** — the wrapper signs a fresh JWT per send. Supply an optional `TokenCacheHook` on `FcmConfig` to amortize signing cost. Cache key shape: `fcm:<projectId>`. Epoch is **seconds** (PHP `time()`) — divergent from TypeScript sibling's ms.

### Optional `TokenCacheHook`

This connector signs a fresh JWT on every send by default (stateless — the wrapper holds no state). Consumers that want to amortize the signing cost supply a `Thinwrap\Notifications\Contract\TokenCacheHook` on the config. The hook owns the state — the wrapper calls `get` / `set`. Epoch is **seconds** (PHP `time()`) — divergent from the TypeScript sibling's milliseconds.

## Endpoint

`https://fcm.googleapis.com/v1/projects/{projectId}/messages:send`

## Rate limits

See vendor docs: <https://firebase.google.com/docs/cloud-messaging/concept-options#rate-limiting>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 2** (auth-heavy: JWT signing or AWS SigV4).

## `_passthrough` example

Forward FCM v1-specific fields (e.g. `android.priority`, `webpush.fcm_options`, `apns.headers`) via `_passthrough.body`.
