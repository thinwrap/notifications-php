# Telnyx Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Telnyx` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Telnyx\TelnyxConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new TelnyxConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Telnyx, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`TelnyxConfig(apiKey: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API key from Telnyx Mission Control. Sent as `Authorization: Bearer <key>`.

## Endpoint

`https://api.telnyx.com/v2/messages` — single global endpoint, JSON body.

## Narrowed input augmentations

`to` → `to`; `body` → `text`; `from` (per-send or `config.from`) → `from`. Either a `from` or a `messagingProfileId` is required. Telnyx wraps success in a JSON:API `{ data: {...} }` envelope; on success `providerMessageId` unwraps `data.id` and the full envelope stays on `raw`.

`TelnyxNarrowedInput` extends `SmsSendInput` with below-baseline Telnyx fields, written to the wire in snake_case: `messagingProfileId` (`messaging_profile_id`), `webhookUrl` (`webhook_url`), `webhookFailoverUrl` (`webhook_failover_url`), `useProfileWebhooks` (`use_profile_webhooks`), `autoDetect` (`auto_detect`), `mediaUrls` (`media_urls`), plus `type` and `subject`. Any further Telnyx-specific snake_case fields go via `_passthrough.body`.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| 4xx | `errors[0].code` `to_number_invalid` / `messaging_profile_id_not_found` / `no_to_number_provided` | `InvalidRecipient` |
| other 4xx (incl. bare 422) | (no matching `code`) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

The vendor `code`/`detail`/`title` are read from `errors[0]`. Before dispatch, a request with no `from`/`config.from` **and** no `messagingProfileId` raises `InvalidRequest` (400) locally.

> **Divergence from the Go sibling doc:** the Go doc maps any bare 422 to `InvalidRecipient`. The PHP connector escalates to `InvalidRecipient` **only** when `errors[0].code` is one of the three recipient codes above; a bare 422 with no matching code falls through to `InvalidRequest`. This README follows the PHP code.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Telnyx-specific fields (e.g. `messaging_profile_id`, `webhook_url`, `auto_detect`) via `_passthrough.body`.

## Vendor docs

- API reference: https://developers.telnyx.com/docs/messaging/messages/send-message
- Errors: https://developers.telnyx.com/docs/messaging/messages/error-codes/index
- Rate limits: https://developers.telnyx.com/docs/messaging/messaging-limits
