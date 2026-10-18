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

`TwilioConfig(accountSid: string, authToken: string, from: ?string = null, region: ?TwilioRegion = null, edge: ?TwilioEdge = null)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Account SID + Auth Token from Twilio Console. HTTP Basic auth. A non-US processing region needs credentials created in that region.

## Endpoint

`https://api.twilio.com/2010-04-01/Accounts/{accountSid}/Messages.json` (default `us1` processing region). Form-encoded request body; JSON response.

`region` (`TwilioRegion`) is the processing region and `edge` (`TwilioEdge`) where the request enters Twilio's network. The host follows Twilio's `api.{edge}.{region}.twilio.com` format:

| `region` | `edge` omitted | `edge` set |
|---|---|---|
| `Us1` (default) | `api.twilio.com` | `api.{edge}.us1.twilio.com` |
| `Ie1` | `api.dublin.ie1.twilio.com` | `api.{edge}.ie1.twilio.com` |
| `Au1` | `api.sydney.au1.twilio.com` | `api.{edge}.au1.twilio.com` |

Edges: `Sydney`, `SaoPaulo`, `Dublin`, `Frankfurt`, `Tokyo`, `Singapore`, `Ashburn`, `Umatilla`, `Roaming`.

**`Au1` caveat:** Twilio's regional product-availability page lists only Programmable Voice and Elastic SIP Trunking for AU1, not Messaging, so the Messages resource may not be served there.

`Us2`, `Br1`, `De1`, `Jp1` and `Sg1` are deprecated: they are edge locations, not REST processing regions (Twilio processes them in US1), and their old `api.{region}.twilio.com` hosts stopped working on 2026-04-28. They are rejected with `InvalidRequest` before any request is sent — use `Us1` with the matching `TwilioEdge` (`Umatilla`, `SaoPaulo`, `Frankfurt`, `Tokyo`, `Singapore`) instead.

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
- Regions and edges: <https://www.twilio.com/docs/global-infrastructure/edge-locations>, <https://www.twilio.com/docs/global-infrastructure/edge-locations/legacy-regions>
- Product availability per region: <https://www.twilio.com/docs/global-infrastructure/regional-product-and-feature-availability>
- Rate limits: <https://www.twilio.com/docs/usage/api#rate-limits>
