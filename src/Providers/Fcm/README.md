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

`FcmConfig(serviceAccount: array, tokenCache: ?TokenCacheHook = null)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Service-account JSON from Firebase Console → Project Settings → Service accounts. JWT RS256 signed locally, exchanged for OAuth2 access token at `https://oauth2.googleapis.com/token`. **Stateless by default** — the wrapper signs a fresh JWT per send. Supply an optional `TokenCacheHook` on `FcmConfig` to amortize signing cost. Cache key shape: `fcm:<projectId>:<clientEmail>`. Epoch is **seconds** (PHP `time()`) — divergent from TypeScript sibling's ms.

### Optional `TokenCacheHook`

This connector signs a fresh JWT on every send by default (stateless — the wrapper holds no state). Consumers that want to amortize the signing cost supply a `Thinwrap\Notifications\Contract\TokenCacheHook` on the config. The hook owns the state — the wrapper calls `get` / `set`. Epoch is **seconds** (PHP `time()`) — divergent from the TypeScript sibling's milliseconds.

## Endpoint

`https://fcm.googleapis.com/v1/projects/{projectId}/messages:send`

## Narrowed input augmentations

Standard push input (`to`, `title`, `body`, `data` — the 4-field baseline). `FcmPushSendInput` extends `PushSendInput` with FCM-narrowed fields: `ttl` (int seconds; emitted as `android.ttl = "<n>s"`) plus the platform blocks `android`, `apns`, `webpush`, and `fcm_options` (each `array<string,mixed>`, populated with documented FCM HTTP v1 fields). Note the `apns` block here is the FCM-side APNs payload nested inside an FCM message, not the standalone APNs connector. Rarely-used FCM `message` fields (topic/condition targeting, etc.) flow through `_passthrough.body`.

## Error mapping

| Vendor HTTP | Vendor signal (`error.status`) | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 404 | (any) | `InvalidRecipient` |
| 400 | (any) | `InvalidRequest` |
| 429 | (any) | `RateLimited` |
| 500 / 503 | (any) | `ProviderUnavailable` |
| (other status) | `UNAUTHENTICATED`, `SENDER_ID_MISMATCH` | `AuthFailed` |
| (other status) | `INVALID_ARGUMENT` | `InvalidRequest` |
| (other status) | `NOT_FOUND` | `InvalidRecipient` |
| (other status) | `QUOTA_EXCEEDED`, `RESOURCE_EXHAUSTED` | `RateLimited` |
| (other status) | `UNAVAILABLE`, `INTERNAL` | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

HTTP status is classified first; the FCM `error.status` string (from the JSON `error.status` field) is consulted only for status codes not covered by the rows above. Auth-flow failures (invalid private key, `openssl_sign` failure, or a failed OAuth token exchange) surface as `AuthFailed` before the send.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward FCM v1-specific fields (e.g. `android.priority`, `webpush.fcm_options`, `apns.headers`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages/send>
- OAuth2 auth: <https://firebase.google.com/docs/cloud-messaging/auth-server>
- Error codes: <https://firebase.google.com/docs/cloud-messaging/error-codes>
- Throttling & quotas: <https://firebase.google.com/docs/cloud-messaging/throttling-and-quotas>
