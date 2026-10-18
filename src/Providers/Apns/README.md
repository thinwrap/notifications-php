# Apple Push Notification service Push Connector (PHP)

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

`ApnsConfig(keyId: string, teamId: string, bundleId: string, privateKey: string, production: bool = true, tokenCache: ?TokenCacheHook = null)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

> **APNs requires HTTP/2.** The connector pins the request to HTTP/2 (`withProtocolVersion('2.0')`) so a curl-backed PSR-18 client (libcurl built with nghttp2) or Symfony HttpClient negotiates h2. The PSR-18 client you supply — or that `php-http/discovery` selects — **must** be HTTP/2-capable; a strictly HTTP/1.1 client will fail, as APNs rejects HTTP/1.1.

## Auth setup

Auth Key (`.p8` file) + Key ID + Team ID from Apple Developer portal. JWT ES256 signed locally with the EC private key. Sandbox endpoint at `api.sandbox.push.apple.com`. The wrapper is stateless: without a `TokenCacheHook` it signs a fresh JWT per send. **Supply `tokenCache` in production** — see below. Cache key shape: `apns:<teamId>:<keyId>:<bundleId>`. Epoch is **seconds** — divergent from TypeScript sibling's ms.

### `TokenCacheHook` is required in production

Apple: "Refresh your token no more than once every 20 minutes and no less than once every 60 minutes", and APNs answers `429 TooManyProviderTokenUpdates` when a new token arrives more often than that. Without a cache every send signs a new JWT, so anything beyond a trickle of pushes gets rejected — the hook is what keeps APNs working, not a speed optimisation. Supply a `Thinwrap\Notifications\Contract\TokenCacheHook` on the config (backed by APCu, Redis, a static, …); the hook owns the state and the wrapper only calls `get` / `set`, caching each token for 50 minutes (inside Apple's 20–60 minute window). Epoch is **seconds** (PHP `time()`) — divergent from the TypeScript sibling's milliseconds.

## Endpoint

`https://api.push.apple.com/3/device/{deviceToken}`. The sandbox environment uses the host `api.sandbox.push.apple.com`. JSON request body; APNs returns an empty 200 body (the message id is the `apns-id` response header).

## Narrowed input augmentations

Standard push input (`to`, `title`, `body`, `data`) applies: `title`/`body` → `aps.alert.{title,body}`, and `data` keys merge at the payload root alongside `aps`. The `apns-push-type` header is your own when you set one (`apnsPushType`, or `apns-push-type` in `_passthrough['headers']`, which wins); otherwise it is `alert` when the final `aps` (after `aps` and `_passthrough` are merged) has an `alert`, `badge` or `sound` — Apple's alert type is for "an alert, badge, or sound" — and `background` when it has none of them. A background push (inferred or yours) follows Apple's rules: `apns-priority: 5` (Apple: "Using priority `10` is an error", and 10 is the default when the header is omitted) and `aps.content-available: 1`, each unless you set it yourself. `aps.alert` may be a string (sent as-is) or a dictionary; combined with `title` / `body` a string alert becomes the dictionary's `body`. An empty alert is never sent, and `aps` is always a JSON object. APNs returns an empty 200 body, so `raw` is `[]`, `status` is `Sent`, and `providerMessageId` is the `apns-id` response header.

`ApnsPushSendInput` extends `PushSendInput` with below-baseline APNs fields: `aps` (Apple-spec dictionary; kebab-case keys written verbatim), `badge`, `sound`, `ttl` (computes the `apns-expiration` header as `now + ttl`), plus header overrides `apnsTopic`, `apnsPushType`, `apnsPriority`, `apnsCollapseId`, `apnsExpiration`. Any further sub-baseline body fields (the rest of the `aps` dictionary) go via `_passthrough['body']`; sub-baseline headers (`apns-priority`, `apns-expiration`, `apns-collapse-id`, `apns-push-type`/`apns-topic` overrides) go via `_passthrough['headers']`.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 400 | `reason` `BadDeviceToken` | `InvalidRecipient` |
| 400 | (other) | `InvalidRequest` |
| 403 | `reason` `BadDeviceToken` | `InvalidRecipient` |
| 403 | (other) | `AuthFailed` |
| 410 | (any) | `InvalidRecipient` |
| 413 | (any) | `InvalidRequest` |
| 429 | `reason` `TooManyProviderTokenUpdates` (no `tokenCache`; the message says so) | `AuthFailed` |
| 429 | (other) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

The `reason` string is read from the JSON error body. `TooManyProviderTokenUpdates` is `AuthFailed` rather than `RateLimited` because retrying without a `tokenCache` signs yet another token and fails the same way.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward APNs-specific fields (e.g. `aps.thread-id`, `aps.relevance-score`, custom headers) via `_passthrough.body` and `_passthrough.headers`.

## Vendor docs

- API reference: <https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns>
- Authentication: <https://developer.apple.com/documentation/usernotifications/establishing-a-token-based-connection-to-apns>
- Errors: <https://developer.apple.com/documentation/usernotifications/handling-notification-responses-from-apns>
