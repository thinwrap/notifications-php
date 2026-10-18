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

`instanceId` becomes part of the request host, so it must be a single DNS label (letters, digits, hyphens); anything else is `InvalidRequest` before sending.

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Instance ID + Secret Key from Pusher Beams dashboard. Sent as `Authorization: Bearer <secretKey>`.

## Endpoint

`https://{instanceId}.pushnotifications.pusher.com/publish_api/v1/instances/{instanceId}/publishes`

## Narrowed input augmentations

Beams is the canonical outlier: the publisher must send parallel `fcm` / `apns` / `web` platform payloads in one request, which the connector synthesizes from the baseline. `title`/`body` → each platform's `notification`/`alert`; `data` → `fcm.data` (JSON-stringified to satisfy FCM's string-only constraint), `web.data` (original types preserved) and `apns.data` (beside `aps`, as in Beams' publish-payload example). With neither title nor body the push is data-only: `fcm.notification`, `web.notification` and `apns.aps.alert` are left out rather than sent empty, and iOS gets `aps: {"content-available": 1}` (Apple's background push). By default `to` → `users: [to]`. On success `status` is `Sent` and `providerMessageId` comes from the response `publishId`. The URL suffix is `/publishes/interests` when the narrowed `interests` field is set, else `/publishes/users`.

`PusherBeamsPushSendInput` extends `PushSendInput` with below-baseline Beams fields: `badge`/`sound` (merged into `apns.aps`), `ttl` (→ `fcm.android.ttl` = `"<ttl>s"` and `apns.apns-expiration`), `users`, `interests`, and the `fcm`/`apns`/`web` platform-block overrides (shallow-merged onto the synthesized blocks, with `apns.aps` deep-merged). Recipient routing precedence is `interests` > `users` > `[to]`: supplying an `interests` or `users` field overrides the default `users: [to]`.

Any further Beams-specific fields go via `_passthrough['body']`, merged onto the synthesized wire body.

## Error mapping

Follows the Beams publish API's status table:

| Vendor HTTP (Beams meaning) | `ProviderCode` |
|---|---|
| 400 (bad content type, missing instance id / auth header, body too large) | `InvalidRequest` |
| 401 ("Incorrect API Key") / 403 | `AuthFailed` |
| 402 ("Publishing has been blocked due to being over plan limits") | `RateLimited` |
| 404 ("Could not find the instance" — check `instanceId`) | `InvalidRequest` |
| 422 ("JSON does not match our schema") / other 4xx | `InvalidRequest` |
| 429 ("Too many requests", max 100 RPS) | `RateLimited` |
| 5xx | `ProviderUnavailable` |

Classification is driven purely by HTTP status; the JSON `error` and `description` fields (when present) become `$e->providerMessage` (`error: description`).

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Pusher Beams fields. **Outlier**: at the wire layer the connector synthesizes both `apns.aps` and `fcm.notification` payloads from the normalized input so a single send fans out across both platforms.

## Vendor docs

- API reference: <https://pusher.com/docs/beams/reference/publish-api/>
- Errors: <https://pusher.com/docs/beams/reference/publish-api/#error-responses>
