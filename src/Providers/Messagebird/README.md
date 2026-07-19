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

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Access key from MessageBird dashboard. Sent as `Authorization: AccessKey <key>` header.

## Endpoint

`https://rest.messagebird.com/messages`

## Rate limits

See vendor docs: <https://developers.messagebird.com/api/sms-messaging/>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward MessageBird-specific fields (e.g. `reference`, `validity`, `gateway`) via `_passthrough.body`.
