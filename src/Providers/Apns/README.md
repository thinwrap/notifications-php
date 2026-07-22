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

Auth Key (`.p8` file) + Key ID + Team ID from Apple Developer portal. JWT ES256 signed locally with the EC private key. Sandbox endpoint at `api.sandbox.push.apple.com`. **Stateless by default** — the wrapper signs a fresh JWT per send. Supply an optional `TokenCacheHook` on `ApnsConfig` to amortize signing cost. Cache key shape: `apns:<teamId>:<keyId>:<bundleId>`. Epoch is **seconds** — divergent from TypeScript sibling's ms.

### Optional `TokenCacheHook`

This connector signs a fresh JWT on every send by default (stateless — the wrapper holds no state). Consumers that want to amortize the signing cost supply a `Thinwrap\Notifications\Contract\TokenCacheHook` on the config. The hook owns the state — the wrapper calls `get` / `set`. Epoch is **seconds** (PHP `time()`) — divergent from the TypeScript sibling's milliseconds.

## Endpoint

`https://api.push.apple.com/3/device/{deviceToken}`. The sandbox environment uses the host `api.sandbox.push.apple.com`. JSON request body; APNs returns an empty 200 body (the message id is the `apns-id` response header).

## Narrowed input augmentations

Standard push input (`to`, `title`, `body`, `data`) applies: `title`/`body` → `aps.alert.{title,body}`, and `data` keys merge at the payload root alongside `aps`. The `apns-push-type` header is `alert` when a title or body is present, else `background`. APNs returns an empty 200 body, so `raw` is `[]`, `status` is `Sent`, and `providerMessageId` is the `apns-id` response header.

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
| 429 | (any) | `RateLimited` |
| 500 / 503 | (any) | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

The `reason` string is read from the JSON error body. Note that only `500`/`503` map to `ProviderUnavailable` — other `5xx` statuses fall through to `Unknown`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward APNs-specific fields (e.g. `aps.thread-id`, `aps.relevance-score`, custom headers) via `_passthrough.body` and `_passthrough.headers`.

## Vendor docs

- API reference: <https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns>
- Authentication: <https://developer.apple.com/documentation/usernotifications/establishing-a-token-based-connection-to-apns>
- Errors: <https://developer.apple.com/documentation/usernotifications/handling-notification-responses-from-apns>
