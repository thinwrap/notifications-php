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

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Account SID + Auth Token from Twilio Console. HTTP Basic auth. Regional clusters available — see `TwilioRegion` enum.

## Endpoint

`https://api.twilio.com/2010-04-01/Accounts/{accountSid}/Messages.json` (default `us1` cluster). Other regional clusters swap the host — e.g. `api.us2.twilio.com`, `api.ie1.twilio.com`. Available regions: `us1` (default), `us2`, `ie1`, `au1`, `br1`, `de1`, `jp1`, `sg1`. Form-encoded request body; JSON response.

## Narrowed input augmentations

Standard SMS input (`to`, `body`, `from`) applies. `TwilioNarrowedInput` extends `SmsSendInput` with below-baseline Twilio fields, written to the wire in PascalCase: `messagingServiceSid`, `mediaUrl` (`list<string>`, emitted as repeated `MediaUrl=` form fields — never comma-joined), `statusCallback`, `applicationSid`, `maxPrice`, `provideFeedback`, `validityPeriod`, `forceDelivery`, `contentRetention`, `addressRetention`, `smartEncoded`, `persistentAction`, `shortenUrls`, `scheduleType`, `sendAt`, `sendAsMms`, `contentVariables`, `riskCheck`, `contentSid`. Any further Twilio-specific PascalCase fields go via `_passthrough.body`.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 400 | `code` 20003 | `AuthFailed` |
| 400 | `code` 21211 / 21408 / 21610 / 21612 / 21614 | `InvalidRecipient` |
| 400 / other 4xx | (no matching `code`) | `InvalidRequest` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

The Twilio numeric `code` (from the JSON error body) refines a 400 before the generic `InvalidRequest` fallback applies. Before dispatch, a request with no `from`/`config.from` **and** no `messagingServiceSid` raises `InvalidRequest` (400) locally.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Twilio-specific PascalCase fields (e.g. `MessagingServiceSid`, `StatusCallback`, `MaxPrice`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://www.twilio.com/docs/messaging/api/message-resource>
- Error codes: <https://www.twilio.com/docs/api/errors>
- Regions: <https://www.twilio.com/docs/global-infrastructure/edge-locations>
- Rate limits: <https://www.twilio.com/docs/usage/api#rate-limits>
