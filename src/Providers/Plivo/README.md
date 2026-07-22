# Plivo Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Plivo` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Plivo\PlivoConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new PlivoConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Plivo, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`PlivoConfig(authId: string, authToken: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Auth ID + Auth Token from Plivo Console. HTTP Basic auth.

## Endpoint

`https://api.plivo.com/v1/Account/{authId}/Message/` (the trailing slash is required). JSON request body — Plivo is one of the few SMS providers accepting JSON.

## Narrowed input augmentations

`to` → `dst`; `body` → `text`; `from` (per-send `from`, else `config.from`) → `src`. Either a `from`/`config.from` **or** a `powerpackUuid` is required, else `InvalidRequest` (400) is raised locally before dispatch. On success `status` is `Sent`; `message_uuid` is an array and `providerMessageId` reports `message_uuid[0]` (the full list stays on `$result->raw['message_uuid']`).

`PlivoNarrowedInput` extends `SmsSendInput` with below-baseline Plivo fields (India DLT compliance fields are first-class rather than buried in passthrough). The connector rewrites the camelCase fields to Plivo's snake_case wire keys: `powerpackUuid` → `powerpack_uuid`, `dltEntityId` → `dlt_entity_id`, `dltTemplateId` → `dlt_template_id`, `dltTemplateCategory` → `dlt_template_category`, `templateId` → `template_id`; `url`, `method`, `log`, and `trackable` keep their names.

Any further Plivo-specific fields go via `_passthrough['body']`, which merges verbatim — supply the exact snake_case wire keys.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| other 4xx | (any) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

Classification is driven purely by HTTP status; the JSON `error` field (when present) becomes the base `$e->providerMessage`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Plivo-specific fields (e.g. `url`, `method`, `power_pack_uuid`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://www.plivo.com/docs/messaging/api/message/send-a-message>
- Errors: <https://www.plivo.com/docs/messaging/troubleshooting/error-codes>
- Overview: <https://www.plivo.com/docs/messaging/api/overview>
