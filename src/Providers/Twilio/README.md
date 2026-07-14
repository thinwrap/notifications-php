---
providerId: twilio
channel: sms
novuProviderId: twilio
auth:
  method: basic
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://api.twilio.com/2010-04-01/Accounts/{accountSid}/Messages.json
  regional:
    - us1
    - us2
    - ie1
    - au1
    - br1
    - de1
    - jp1
    - sg1
versioning:
  vendorApiVersion: "2010-04-01"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://www.twilio.com/docs/usage/api#rate-limits
retryAfterSurfaced: true
notes_passthrough: |
  Forward Twilio-specific PascalCase fields (e.g. `MessagingServiceSid`,
  `StatusCallback`, `MaxPrice`) via `_passthrough.body`.
---

# Twilio Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Twilio` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Twilio\TwilioConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new TwilioConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Twilio, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`TwilioConfig(accountSid: string, authToken: string, region: ?TwilioRegion = null)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Account SID + Auth Token from Twilio Console. HTTP Basic auth. Regional clusters available — see `TwilioRegion` enum.

## Endpoint

`https://api.twilio.com/2010-04-01/Accounts/{accountSid}/Messages.json`

## Regional endpoints

`us1`, `us2`, `ie1`, `au1`, `br1`, `de1`, `jp1`, `sg1`

## Rate limits

See vendor docs: <https://www.twilio.com/docs/usage/api#rate-limits>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Twilio-specific PascalCase fields (e.g. `MessagingServiceSid`, `StatusCallback`, `MaxPrice`) via `_passthrough.body`.
