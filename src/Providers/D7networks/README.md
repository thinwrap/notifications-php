---
providerId: d7networks
channel: sms
novuProviderId: null
auth:
  method: bearer
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://api.d7networks.com/messages/v1/send
versioning:
  vendorApiVersion: "v1"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://d7networks.com/docs/Messages/Send_Message/
retryAfterSurfaced: true
notes_passthrough: |
  Forward D7-specific fields (e.g. `originator`, `tag`, `report_url`) via
  `_passthrough.body`.
---

# D7 Networks Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::D7networks` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\D7networks\D7networksConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new D7networksConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::D7networks, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`D7networksConfig(token: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Bearer token from D7 dashboard. Sent as `Authorization: Bearer <token>`.

## Endpoint

`https://api.d7networks.com/messages/v1/send`

## Rate limits

See vendor docs: <https://d7networks.com/docs/Messages/Send_Message/>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward D7-specific fields (e.g. `originator`, `tag`, `report_url`) via `_passthrough.body`.
