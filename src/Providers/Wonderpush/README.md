# WonderPush Push Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Wonderpush` is passed to the `Push` facade.

```php
use Thinwrap\Notifications\Push;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Wonderpush\WonderpushConfig;
use Thinwrap\Notifications\DTO\Push\PushSendInput;

$cfg = new WonderpushConfig(/* see config shape below */);
$push = new Push(NotificationProviderId::Wonderpush, $cfg);
$result = $push->send(new PushSendInput(to: 'device-token', title: 'Hello', body: 'Hello, world!'));
```

## Configuration

`WonderpushConfig(applicationId: string, accessToken: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Application ID + Access Token from WonderPush dashboard. The access token is sent as `Authorization: Bearer <accessToken>`.

## Endpoint

`https://management-api.wonderpush.com/v1/deliveries` — single global endpoint, JSON body.

## Narrowed input augmentations

`to` → `targetUserIds: [to]`; `title` → `notification.alert.title`; `body` → `notification.alert.text`; `data` → `notification.push.custom` (WonderPush's custom-data key). `config.applicationId` is forwarded into the body as `applicationId`. On success `providerMessageId` comes from the response `id` (falling back to `notificationId`).

The wire body is camelCase-native. `WonderpushPushSendInput` extends `PushSendInput` with `targetUserIds` (a supplied slice replaces the default `[to]`), `targetSegmentIds`, `customSegmentation`, `subtitle` (→ `notification.alert.ios.subtitle`), `sound` (→ `notification.alert.ios.sound` **and** `notification.alert.android.sound`), `badge` (→ `notification.alert.ios.badge`), `notification` (a block override, deep-merged over the built block — caller wins per leaf, so your own `alert.ios` / `alert.android` / `push` keys sit alongside the synthesized ones), `actions`, and `categories`. Any further WonderPush fields go via `_passthrough.body` (merged verbatim — no casing transform).

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 | (any) | `AuthFailed` |
| 404 | (any) | `InvalidRecipient` |
| 429 | (any) | `RateLimited` |
| 500 / 503 | (any) | `ProviderUnavailable` |
| 400 (and other 4xx) | (any) | `InvalidRequest` |
| other 5xx | (any) | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

The error message is read from the JSON `error.message` field.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward WonderPush-specific fields (e.g. `targetSegmentIds`, `notification`, `actions`) via `_passthrough.body`.

## Vendor docs

- API reference: https://docs.wonderpush.com/reference/post-deliveries
- Notification object: https://docs.wonderpush.com/reference/notification
- Auth: https://docs.wonderpush.com/reference/authentication
- Rate limits: https://docs.wonderpush.com/reference/rate-limiting
