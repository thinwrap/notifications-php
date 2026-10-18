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

`OneSignalConfig(appId: string, restApiKey: string)` — `restApiKey` takes either key generation (current App API key `os_v2_…` or a legacy REST API key); the connector picks endpoint and auth scheme from it.

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

App API key from OneSignal dashboard → Settings → Keys & IDs.

| Key | Detected by | Auth header |
|---|---|---|
| Current App API key | starts with `os_v2_` | `Authorization: Key <key>` |
| Legacy REST API key (no longer issuable since Nov 2024) | anything else | `Authorization: Basic <key>` (verbatim, no base64) |

## Endpoint

- Current key: `POST https://api.onesignal.com/notifications?c=push`
- Legacy key: `POST https://onesignal.com/api/v1/notifications`

## Narrowed input augmentations

By default `to` → `include_subscription_ids: [to]`; `title` → `headings.en`; `body` → `contents.en`; `data` → `data`. On success `status` is `Sent` and `providerMessageId` comes from the response `id`.

OneSignal returns **HTTP 200 even when all recipients are invalid**. A 200 with a notification `id` is a success even when `errors` lists some invalid recipients (OneSignal's "Message Sent with Errors": the valid ones were notified, so a retry would notify them twice) — the `errors` stay on `$result->raw`. Only when the `id` is missing or empty is the `errors` field inspected, and known invalid-recipient markers (`all included players are not subscribed`, `invalid_player_ids`, `invalid_external_user_ids`, `no subscriptions`, or non-empty `invalid_external_user_ids` / `invalid_player_ids` / `invalid_aliases` arrays) throw `InvalidRecipient`. A 200 with no `id` and no known marker throws `InvalidRequest`.

`OneSignalPushSendInput` extends `PushSendInput` with below-baseline OneSignal fields, spelled in snake_case to match the wire verbatim (no casing transform): `include_external_user_ids`, `include_player_ids`, `included_segments`, `excluded_segments`, `headings`, `contents`, `ios_attachments`, `big_picture`, `android_channel_id`, `ios_sound`, `android_sound`, `send_after`, `delayed_option`, `priority`, `ttl`, `external_id`, `collapse_id`. Localized `headings`/`contents` maps win over the base `title`/`body` singletons. Supplying any recipient-routing key (`include_external_user_ids`, `include_player_ids`, `included_segments`, `excluded_segments`) **suppresses** the default `include_subscription_ids`. The same holds for any targeting key in `_passthrough['body']` — `include_aliases` (with its required `target_channel`), `include_subscription_ids`, `included_segments`, `excluded_segments`, `filters`, `include_player_ids`, `include_external_user_ids`, `include_email_tokens`, `include_phone_numbers` — because OneSignal rejects mixed targeting.

Any further OneSignal-specific fields go via `_passthrough['body']`, which merges verbatim.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 (key lacks permission) | (any) | `AuthFailed` |
| 400 | (any) | `InvalidRequest` |
| 404 | (any) | `InvalidRequest` |
| 429 | (any) | `RateLimited` |
| 500 / 503 / other 5xx | (any) | `ProviderUnavailable` |
| 200 | empty `id`, `errors` matches an invalid-recipient marker | `InvalidRecipient` |
| 200 | no `id` (and no marker) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

The HTTP-status rows are driven by `mapVendorError`; the two `200` rows are the body-layer soft-error path (`mapTicketError` + the no-`id` guard) that fires because OneSignal reports recipient failures inside a 200 response. All are surfaced as thrown `ConnectorError`s.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward OneSignal-specific fields (e.g. `include_aliases` + `target_channel`, `filters`, `included_segments`, `send_after`) via `_passthrough.body`; a targeting key there replaces the default `include_subscription_ids: [to]`. Invalid recipient lists are surfaced as a thrown `ConnectorError(providerCode: InvalidRecipient)` — see Error mapping above.

## Vendor docs

- API reference: <https://documentation.onesignal.com/reference/push-notification>
- Keys & IDs (key generations, base URLs): <https://documentation.onesignal.com/docs/en/keys-and-ids>
- Errors: <https://documentation.onesignal.com/reference/rate-limits>
