# Pusher Beams Push Connector (PHP)

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

`PusherBeamsConfig(instanceId: string, secretKey: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Instance ID + Secret Key from Pusher Beams dashboard. Sent as `Authorization: Bearer <secretKey>`.

## Endpoint

`https://{instanceId}.pushnotifications.pusher.com/publish_api/v1/instances/{instanceId}/publishes`

## Narrowed input augmentations

Beams is the canonical outlier: the publisher must send parallel `fcm` / `apns` / `web` platform payloads in one request, which the connector synthesizes from the baseline. `title`/`body` → each platform's `notification`/`alert` (null title/body are dropped, yielding a data-only push); `data` → `fcm.data` (JSON-stringified to satisfy FCM's string-only constraint) and `web.data` (original types preserved). By default `to` → `users: [to]`. On success `status` is `Sent` and `providerMessageId` comes from the response `publishId`. The URL suffix is `/publishes/interests` when the narrowed `interests` field is set, else `/publishes/users`.

`PusherBeamsPushSendInput` extends `PushSendInput` with below-baseline Beams fields: `badge`/`sound` (merged into `apns.aps`), `ttl` (→ `fcm.android.ttl` = `"<ttl>s"` and `apns.apns-expiration`), `users`, `interests`, and the `fcm`/`apns`/`web` platform-block overrides (shallow-merged onto the synthesized blocks, with `apns.aps` deep-merged). Recipient routing precedence is `interests` > `users` > `[to]`: supplying an `interests` or `users` field overrides the default `users: [to]`.

Any further Beams-specific fields go via `_passthrough['body']`, merged onto the synthesized wire body.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 | (any) | `AuthFailed` |
| 400 | (any) | `InvalidRequest` |
| 422 | (any) | `InvalidRecipient` |
| 429 | (any) | `RateLimited` |
| 500 / 503 | (any) | `ProviderUnavailable` |
| — | (unmatched, incl. other 5xx) | `Unknown` |

Classification is driven purely by HTTP status (only 500 and 503 map to `ProviderUnavailable`; other 5xx fall through to `Unknown`); the JSON `error` field (when present) becomes the base `$e->providerMessage`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Pusher Beams fields. **Outlier**: at the wire layer the connector synthesizes both `apns.aps` and `fcm.notification` payloads from the normalized input so a single send fans out across both platforms.

## Vendor docs

- API reference: <https://pusher.com/docs/beams/reference/publish-api/>
- Errors: <https://pusher.com/docs/beams/reference/publish-api/#error-responses>
