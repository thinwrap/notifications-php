# Infobip Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Infobip` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Infobip\InfobipConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new InfobipConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Infobip, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`InfobipConfig(baseUrl: string, apiKey: string, from: ?string = null)`

`baseUrl` is your account's host from the Infobip portal (e.g. `xyz123.api.infobip.com`) as a bare hostname — no `https://`, path, port or trailing slash. It receives the API key, so anything else is `InvalidRequest` before sending.

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API key from Infobip Portal. Sent as `Authorization: App <key>` header (note the `App` prefix).

## Endpoint

`https://{baseUrl}/sms/2/text/advanced`

## Narrowed input augmentations

Standard SMS input (`to`, `body`, `from`) applies. Infobip's v2 advanced endpoint takes a `messages` array even for one message: the connector builds `messages: [{ from, destinations: [{to}], text }]` from `from` (per-send or `config.from`, **required** — a missing sender raises `ConnectorError(InvalidRequest)` before any HTTP call), `to`, and `body`. On success `status` is `Sent` and `providerMessageId` comes from `messages[0].messageId`. Infobip answers HTTP 200 even when it rejects the message at submission, so a 200 whose `messages[0].status` is in group 5 (`REJECTED`) throws — see Error mapping.

`InfobipNarrowedInput` extends `SmsSendInput` with below-baseline Infobip fields, written verbatim in Infobip's camelCase wire form: per-message `callbackData`, `notifyUrl`, `notifyContentType`, `validityPeriod`, `flash`, `language` (a language code, sent as `language: {languageCode}`), `transliteration`, and `scheduleSettings['sendAt']` (→ `messages[0].sendAt`); top-level `bulkId`. `validityPeriod` is sent as whole minutes (the v2 endpoint has no time-unit field): `validityPeriodTimeUnit` (`SECONDS` / `MINUTES` / `HOURS`, default minutes) only tells the connector how to convert it — seconds round up — and any other unit raises `InvalidRequest` before dispatch. Any further Infobip-specific camelCase fields go via `_passthrough['body']` (no casing transform) — top-level fields merge directly, while sub-baseline per-message fields require overriding the `messages` key.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| ≥500 | (any) | `ProviderUnavailable` |
| other ≥400 | (any) | `InvalidRequest` |
| 200 | `messages[0].status` group 5 / `REJECTED`, name contains `DESTINATION` / `PREFIX` / `MISSING_TO` / `DND` / `NOT_REGISTERED` | `InvalidRecipient` |
| 200 | `REJECTED_FLOODING_FILTER` | `RateLimited` |
| 200 | other `REJECTED_*` (e.g. `REJECTED_NOT_ENOUGH_CREDITS`, `REJECTED_SENDER`) | `InvalidRequest` |
| — | (unmatched, <400) | `Unknown` |

The error body shape is `{requestError:{serviceException:{messageId,text}}}`; the `EC_*` `messageId` is preserved on `$e->cause['raw']` but not individually mapped — HTTP status drives classification. The `200` rows come from the submission status Infobip returns per message; the error keeps `statusCode: 200` and the full response on `$e->cause['raw']`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Per-message fields (`flash`, `validityPeriod`, `notifyUrl`, …) are `InfobipNarrowedInput` fields; a top-level `_passthrough.body` key lands beside `messages`, not inside it, so use passthrough only for top-level fields or override the whole `messages` array.

## Vendor docs

- API reference: <https://www.infobip.com/docs/api/channels/sms/sms-api/send-sms-message>
- Account base URL: <https://www.infobip.com/docs/essentials/base-url>
