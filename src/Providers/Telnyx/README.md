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

`TelnyxConfig(apiKey: string, from: ?string = null)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API key from Telnyx Mission Control. Sent as `Authorization: Bearer <key>`.

## Endpoint

`https://api.telnyx.com/v2/messages` — single global endpoint, JSON body.

## Narrowed input augmentations

`to` → `to`; `body` → `text`; `from` (per-send or `config.from`) → `from`. Either a `from` or a `messagingProfileId` is required. Telnyx wraps success in a JSON:API `{ data: {...} }` envelope; on success `providerMessageId` unwraps `data.id` and the full envelope stays on `raw`.

`TelnyxNarrowedInput` extends `SmsSendInput` with below-baseline Telnyx fields, written to the wire in snake_case: `messagingProfileId` (`messaging_profile_id`), `webhookUrl` (`webhook_url`), `webhookFailoverUrl` (`webhook_failover_url`), `useProfileWebhooks` (`use_profile_webhooks`), `autoDetect` (`auto_detect`), `mediaUrls` (`media_urls`), plus `type` and `subject`. Any further Telnyx-specific snake_case fields go via `_passthrough.body`.

## Error mapping

The Telnyx `errors[0].code` (a numeric string) is matched first; the HTTP status applies only when no code matches.

| `errors[0].code` | Meaning (Telnyx) | `ProviderCode` |
|---|---|---|
| `40300`, `40309`, `40310` | STOP-blocked recipient, invalid destination region, invalid `to` | `InvalidRecipient` |
| `10002` with `source.pointer` `/to` | invalid phone number in `to` | `InvalidRecipient` |
| `10009`, `10010`, `20001`, `20006`, `40311`, `40313` | authentication / authorization, API key, access token, messaging-profile secret | `AuthFailed` |
| `10011`, `40318` | too many requests, message queue full | `RateLimited` |
| `40301`–`40308`, `40314`, `40316`, `40317`, `40319`–`40325`, `40328`, `40331`, `40333`, `10002`, `10004`, `10015`, `20100` | request / account problems (e.g. invalid `from`, messaging disabled, no content, insufficient funds) | `InvalidRequest` |

| Vendor HTTP (no matching code) | `ProviderCode` |
|---|---|
| 401 / 403 | `AuthFailed` |
| 429 | `RateLimited` |
| 5xx | `ProviderUnavailable` |
| other 4xx (incl. bare 400 / 422) | `InvalidRequest` |
| — | `Unknown` |

The 400xx codes are delivery errors Telnyx reports only in the `message.finalized` webhook, so they never reach the send response. The vendor `code`/`detail`/`title` are read from `errors[0]`. Before dispatch, a request with no `from`/`config.from` **and** no `messagingProfileId` raises `InvalidRequest` (400) locally.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Telnyx-specific fields (e.g. `messaging_profile_id`, `webhook_url`, `auto_detect`) via `_passthrough.body`.

## Vendor docs

- API reference: https://developers.telnyx.com/docs/messaging/messages/send-message
- Errors: https://developers.telnyx.com/docs/messaging/messages/error-codes/index
- Rate limits: https://developers.telnyx.com/docs/messaging/messaging-limits
