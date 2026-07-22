# MessageBird Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Messagebird` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Messagebird\MessagebirdConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new MessagebirdConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Messagebird, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`MessagebirdConfig(accessKey: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Access key from MessageBird dashboard. Sent as `Authorization: AccessKey <key>` header.

## Endpoint

`https://rest.messagebird.com/messages`

## Narrowed input augmentations

`from` (per-send `from`, else `config.from`) is **required** and maps to the wire `originator`; `to` maps to `recipients` (wrapped as `[to]` — MessageBird requires a recipients array even for a single recipient); `body` → `body`. On success `status` is `Sent` and `providerMessageId` comes from the response `id`.

`MessagebirdNarrowedInput` extends `SmsSendInput` with below-baseline MessageBird fields: `type`, `reference`, `validity`, `gateway`, `typeDetails`, `dataCoding`, `mclass`, `scheduledDatetime`. MessageBird's wire keys are mostly camelCase, so most of these keep their names; the one casing-locality outlier is `dataCoding`, which the connector maps to the lowercased-flat wire key `datacoding`.

Any further MessageBird-specific fields go via `_passthrough['body']`, which merges verbatim (no casing transform) — supply the exact wire keys.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| other 4xx | (any) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

Classification is driven purely by HTTP status. The error body shape is `{errors:[{code,description,parameter}]}`; the numeric `code` is preserved on `$e->cause['raw']` but not mapped, and `errors[0].description` (when present) becomes the base `$e->providerMessage`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward MessageBird-specific fields (e.g. `reference`, `validity`, `gateway`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://developers.messagebird.com/api/sms-messaging/>
- Errors: <https://developers.messagebird.com/api/#errors>
