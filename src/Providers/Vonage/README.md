# Vonage Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Vonage` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Vonage\VonageConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new VonageConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Vonage, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`VonageConfig(apiKey: string, apiSecret: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API key + secret from Vonage dashboard. Form-encoded `api_key=...&api_secret=...`. (Vonage was formerly named Nexmo.)

## Endpoint

`https://rest.nexmo.com/sms/json` — `application/x-www-form-urlencoded`.

## Narrowed input augmentations

`from` (per-send or `config.from`) is **required** → `from`; `to` → `to`; `body` → `text`. On success `providerMessageId` comes from `messages[0].message-id`. Vonage returns **HTTP 200 even for soft errors** — `messages[0].status` is the source of truth (see the body-layer table below).

`VonageNarrowedInput` extends `SmsSendInput` with below-baseline Vonage fields, written to the wire in kebab-case: `clientRef` (`client-ref`), `messageClass` (`message-class`), `statusReportReq` (`status-report-req`), plus `type`, `ttl`, and `callback` (written unchanged). Any further Vonage-specific kebab-case fields go via `_passthrough.body`.

## Error mapping

HTTP-layer:

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| other 4xx | (any) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

Body-layer soft error (HTTP 200; `messages[0].status`):

| `messages[0].status` | `ProviderCode` |
|---|---|
| `0` | success |
| `1` | `RateLimited` |
| `4` / `8` / `9` | `AuthFailed` |
| `5` | `ProviderUnavailable` |
| `7` / `29` / `34` | `InvalidRecipient` |
| `2` / `3` / `6` / `11` / `12` / `13` / `14` / `15` / `16` / `19` / `20` / `23` | `InvalidRequest` |
| (unmapped) | `Unknown` |

A 200 response missing `messages[0]` raises `Unknown`. Before dispatch, a request with no `from`/`config.from` raises `InvalidRequest` (400) locally.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Vonage-specific form fields (e.g. `type`, `client-ref`, `callback`) via `_passthrough.body`.

## Vendor docs

- API reference: https://developer.vonage.com/en/api/sms
- Error codes: https://developer.vonage.com/en/api/sms#errors
- Rate limits: https://developer.vonage.com/en/messaging/sms/overview
