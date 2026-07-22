# OneSignal Push Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::OneSignal` is passed to the `Push` facade.

```php
use Thinwrap\Notifications\Push;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\OneSignal\OneSignalConfig;
use Thinwrap\Notifications\DTO\Push\PushSendInput;

$cfg = new OneSignalConfig(/* see config shape below */);
$push = new Push(NotificationProviderId::OneSignal, $cfg);
$result = $push->send(new PushSendInput(to: 'device-token', title: 'Hello', body: 'Hello, world!'));
```

## Configuration

`OneSignalConfig(appId: string, restApiKey: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

REST API Key from OneSignal dashboard → Settings → Keys & IDs. Sent as `Authorization: Basic <key>` header (vendor uses `Basic` despite single-token).

## Endpoint

`https://onesignal.com/api/v1/notifications`

## Narrowed input augmentations

By default `to` → `include_subscription_ids: [to]`; `title` → `headings.en`; `body` → `contents.en`; `data` → `data`. On success `status` is `Sent` and `providerMessageId` comes from the response `id`.

OneSignal returns **HTTP 200 even when all recipients are invalid**; the `errors` field is inspected after the HTTP layer and known invalid-recipient markers (`all included players are not subscribed`, `invalid_player_ids`, `invalid_external_user_ids`, `no subscriptions`, or non-empty `invalid_external_user_ids` / `invalid_player_ids` / `invalid_aliases` arrays) throw `InvalidRecipient`. A 200 with no `id` and no known marker throws `InvalidRequest`.

`OneSignalPushSendInput` extends `PushSendInput` with below-baseline OneSignal fields, spelled in snake_case to match the wire verbatim (no casing transform): `include_external_user_ids`, `include_player_ids`, `included_segments`, `excluded_segments`, `headings`, `contents`, `ios_attachments`, `big_picture`, `android_channel_id`, `ios_sound`, `android_sound`, `send_after`, `delayed_option`, `priority`, `ttl`, `external_id`, `collapse_id`. Localized `headings`/`contents` maps win over the base `title`/`body` singletons. Supplying any recipient-routing key (`include_external_user_ids`, `include_player_ids`, `included_segments`, `excluded_segments`) **suppresses** the default `include_subscription_ids`.

Any further OneSignal-specific fields go via `_passthrough['body']`, which merges verbatim.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 | (any) | `AuthFailed` |
| 400 | (any) | `InvalidRequest` |
| 404 | (any) | `InvalidRequest` |
| 429 | (any) | `RateLimited` |
| 500 / 503 / other 5xx | (any) | `ProviderUnavailable` |
| 200 | `errors` matches an invalid-recipient marker | `InvalidRecipient` |
| 200 | no `id` (and no marker) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

The HTTP-status rows are driven by `mapVendorError`; the two `200` rows are the body-layer soft-error path (`mapTicketError` + the no-`id` guard) that fires because OneSignal reports recipient failures inside a 200 response. All are surfaced as thrown `ConnectorError`s.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward OneSignal-specific fields (e.g. `filters`, `included_segments`, `send_after`) via `_passthrough.body`. Invalid recipient lists are surfaced as a thrown `ConnectorError(providerCode: InvalidRecipient)` — see Error mapping above.

## Vendor docs

- API reference: <https://documentation.onesignal.com/reference/create-notification>
- Errors: <https://documentation.onesignal.com/reference/rate-limits>
