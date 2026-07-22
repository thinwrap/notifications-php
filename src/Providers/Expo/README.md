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

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Optional access token from Expo dashboard (required for enhanced security on paid plans). Sent as `Authorization: Bearer <token>`.

## Endpoint

`https://exp.host/--/api/v2/push/send`

## Narrowed input augmentations

Standard push input applies: `to` → `to`, `title` → `title`, `body` → `body`, `data` → `data`. On success `status` is `Sent` and `providerMessageId` comes from the ticket `id` (Expo's `data` is normalized from either a single ticket object or an array to the first ticket). Expo returns **HTTP 200 even for soft errors**: a ticket with `status: "error"` maps to a `ConnectorError` via the ticket-error table below.

`ExpoPushSendInput` extends `PushSendInput` with below-baseline Expo fields, written verbatim in Expo's camelCase wire form: `sound`, `badge`, `ttl`, `priority`, `channelId`, `categoryId`, `mutableContent`, `subtitle`, `interruptionLevel`, and `displayInForeground` (wire `_displayInForeground`). Any further Expo-specific camelCase fields go via `_passthrough['body']` (no casing transform).

## Error mapping

HTTP-layer:

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| ≥500 | (any) | `ProviderUnavailable` |
| other ≥400 | (any) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

Body-layer ticket error (HTTP 200, `data[].details.error`):

| Ticket error | `ProviderCode` |
|---|---|
| `DeviceNotRegistered` | `InvalidRecipient` |
| `MessageTooBig` | `InvalidRequest` |
| `MessageRateExceeded` | `RateLimited` |
| `MismatchSenderId` | `AuthFailed` |
| `InvalidCredentials` | `AuthFailed` |
| (unmapped) | `Unknown` |

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Expo-specific fields (e.g. `categoryId`, `mutableContent`, `channelId`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://docs.expo.dev/push-notifications/sending-notifications/>
- Errors: <https://docs.expo.dev/push-notifications/sending-notifications/#push-receipt-errors>
- Rate limits: <https://docs.expo.dev/push-notifications/sending-notifications/#rate-limits>
