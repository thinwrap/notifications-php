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

`InfobipConfig(apiKey: string, baseUrl: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

API key from Infobip Portal. Sent as `Authorization: App <key>` header (note the `App` prefix). Novu enum value is `infobip-sms` (channel-suffix divergence).

## Endpoint

`https://api.infobip.com/sms/2/text/advanced`

## Rate limits

See vendor docs: <https://www.infobip.com/docs/api>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Infobip-specific fields (e.g. `flash`, `validityPeriod`, `notifyUrl`) via `_passthrough.body`.
