# SparkPost Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Sparkpost` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Sparkpost\SparkpostConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new SparkpostConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Sparkpost, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`SparkpostConfig(apiKey: string, region: ?SparkpostRegion = null)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API key from SparkPost dashboard. Sent as `Authorization: <key>` header (no `Bearer` prefix — vendor idiom). EU endpoint at `api.eu.sparkpost.com`.

## Endpoint

Region-derived, JSON body:
- `us` (default): `https://api.sparkpost.com/api/v1/transmissions`
- `eu`: `https://api.eu.sparkpost.com/api/v1/transmissions`

## Narrowed input augmentations

Standard Email input maps into the Transmissions shape: `from` (+ `config.senderName`) → `content.from` (`{email, name?}`); `subject` → `content.subject`; `html`/`text` → `content.html`/`.text`; `replyTo` → `content.reply_to` (a **string**); `headers` → `content.headers`; `attachments` → `content.attachments` (`{name, data(base64), type?}`) or `content.inline_images` when `contentId` is set; `tags` → `metadata.tags`. On success `status` is `Queued` and `providerMessageId` comes from `results.id`.

**CC/BCC outlier:** SparkPost has no `cc`/`bcc` fields — `to` seeds `recipients[]`, and each `cc`/`bcc` is appended to `recipients[]` with `address.header_to` set to the primary `to`; CC visibility comes from `content.headers.CC` (comma-joined), BCC stays invisible. There is no narrowed input class; any further SparkPost-specific snake_case fields go via `_passthrough.body` (e.g. `campaign_id`, `substitution_data`, `options`, `metadata`).

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 / 420 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| 413 / 422 | (any) | `InvalidRequest` |
| 400 | first-error `message` matches `recipient` / `address`, or `code` `1300` / `1301` / `1303` | `InvalidRecipient` |
| 400 | (other) | `InvalidRequest` |
| 2xx | `results.total_accepted_recipients == 0` && `total_rejected_recipients > 0` | `InvalidRecipient` |
| — | (unmatched) | `Unknown` |

The first-error `message`/`code` are read from `errors[0]`. A 2xx that rejected **all** recipients is surfaced as an `InvalidRecipient` error so consumers handle it like a 4xx from other providers.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward SparkPost-specific fields. CC/BCC are translated to `recipients[].header_to` + `content.headers.CC` at the wire layer (outlier).

## Vendor docs

- API reference: https://developers.sparkpost.com/api/transmissions/
- EU endpoint: https://developers.sparkpost.com/api/index.html
- Rate limits: https://developers.sparkpost.com/api/index.html#header-rate-limiting
