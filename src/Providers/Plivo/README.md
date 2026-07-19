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

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Auth ID + Auth Token from Plivo Console. HTTP Basic auth.

## Endpoint

`https://api.plivo.com/v1/Account/{authId}/Message/`

## Rate limits

See vendor docs: <https://www.plivo.com/docs/sms/api/overview>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Plivo-specific fields (e.g. `url`, `method`, `power_pack_uuid`) via `_passthrough.body`.
