# TextMagic Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Textmagic` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Textmagic\TextmagicConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new TextmagicConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Textmagic, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`TextmagicConfig(username: string, apiKey: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Username + API v2 key from TextMagic dashboard. Sent as `X-TM-Username` + `X-TM-Key` headers.

## Endpoint

`https://rest.textmagic.com/api/v2/messages` — single global endpoint, `application/x-www-form-urlencoded`.

## Narrowed input augmentations

`body` → `text`; `to` → `phones` (TextMagic's field name; it accepts a comma-separated multi-recipient list there); `from` (per-send or `config.from`, optional) → `from`. On success `providerMessageId` is the response `id` (or `messageId` when returned), stringified.

TextMagic's wire keys are already camelCase, so `TextmagicNarrowedInput` extends `SmsSendInput` with below-baseline fields written to the wire **verbatim**: `templateId`, `sendingTime`, `tz`, `partsCount`, `referenceId`, `rrule`, `cutExtra` (booleans encoded as `'1'`/`'0'`). Any further TextMagic-specific fields go via `_passthrough.body` (merged verbatim into the form; booleans encoded as `'1'`/`'0'`).

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 422 | `message` matches `phone` / `phones` / `recipient` / `number` | `InvalidRecipient` |
| 5xx | (any) | `ProviderUnavailable` |
| other 4xx (incl. bare 422) | (any) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

The error `message`/`code` are read from the JSON body.

> **Divergence from the Go sibling doc:** the Go doc maps any 422 to `InvalidRecipient`. TextMagic exposes no dedicated recipient error code, so the PHP connector escalates a 422 to `InvalidRecipient` **only** when the error `message` names a phone/recipient problem; a bare 422 falls through to `InvalidRequest`. This README follows the PHP code.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward TextMagic-specific fields (e.g. `sendingTime`, `cutExtra`, `partsCount`) via `_passthrough.body`.

## Vendor docs

- API reference: https://docs.textmagic.com/#tag/Outbound-Messages/operation/sendMessage
- Auth: https://docs.textmagic.com/#section/Authentication
