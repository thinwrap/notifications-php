# Sinch Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Sinch` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Sinch\SinchConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new SinchConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Sinch, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`SinchConfig(apiToken: string, servicePlanId: string, region: ?SinchRegion = null)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API token from Sinch dashboard. Sent as `Authorization: Bearer <token>`. Regional clusters available.

## Endpoint

`https://{region}.sms.api.sinch.com/xms/v1/{servicePlanId}/batches` — JSON body. Regional clusters: `us` (default), `eu`.

## Narrowed input augmentations

Standard SMS input (`to`, `body`, `from`) applies. `from` (per-send or `config.from`) is **required**; the batches API expects an array even for a single recipient, so the connector wraps `to` as `[to]`; `body` maps to `body`. On success `providerMessageId` comes from the response `id`.

`SinchNarrowedInput` extends `SmsSendInput` with below-baseline Sinch fields, written to the wire in snake_case: `deliveryReport` (`delivery_report`), `sendAt` (`send_at`), `expireAt` (`expire_at`), `callbackUrl` (`callback_url`), `clientReference` (`client_reference`), `feedbackEnabled` (`feedback_enabled`), `flashMessage` (`flash_message`), plus `type` and `parameters` (passed through unchanged). Any further Sinch-specific snake_case fields go via `_passthrough.body`.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| 4xx | `code` `invalid_destination` / `invalid_recipient` | `InvalidRecipient` |
| other 4xx | (no matching `code`) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

The vendor `code`/`text` are read from the JSON error body and refine a 4xx before the generic `InvalidRequest` fallback. Before dispatch, a request with no `from`/`config.from` raises `InvalidRequest` (400) locally.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Sinch-specific fields (e.g. `delivery_report`, `flash_message`, `feedback_enabled`) via `_passthrough.body`.

## Vendor docs

- API reference: https://developers.sinch.com/docs/sms/api-reference/sms/tag/Batches
- Regions: https://developers.sinch.com/docs/sms/api-reference
