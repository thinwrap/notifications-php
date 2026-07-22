# Mailgun Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Mailgun` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Mailgun\MailgunConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new MailgunConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Mailgun, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`MailgunConfig(apiKey: string, domain: string, region: ?MailgunRegion = null)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API key from Mailgun dashboard. HTTP Basic auth as `api:<key>`. EU endpoint at `api.eu.mailgun.net`.

## Endpoint

`https://api.mailgun.net/v3/{domain}/messages` (`us` region). The `eu` region swaps the host to `api.eu.mailgun.net`. Form-encoded request body (or `multipart/form-data` when attachments are present).

## Narrowed input augmentations

Standard email input applies. The body is sent as `application/x-www-form-urlencoded` (no attachments) or `multipart/form-data` (with attachments): `from` (+ config `senderName`, composed as `Name <addr>`) → `from`, `to` → `to`, `subject` → `subject`, `html`/`text` → `html`/`text`, `cc`/`bcc` → comma-joined `cc`/`bcc`, `replyTo` → `h:Reply-To`, each `headers` entry → `h:<Name>`, and `tags` → repeated `o:tag` fields (**max 3**; extras truncated, emitted in both transport paths). Attachments become `attachment` parts, or `inline` when `contentId` is set. `status` is `Queued` (Mailgun acknowledges queue acceptance, not delivery); `providerMessageId` comes from the response `id`.

Provider-specific form fields ride verbatim in `_passthrough['body']` (no casing transform; Mailgun's wire keys are literal): e.g. `o:tag`, `o:deliverytime`, `o:tracking`, `v:<name>`, `template`, `t:variables`, `h:X-Mailgun-Variables`.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 402 | (any) | `AuthFailed` |
| 400 | `message` matches `not a valid email` / `invalid recipient` / `invalid address` | `InvalidRecipient` |
| 400 | (other) | `InvalidRequest` |
| 404 / 413 | (any) | `InvalidRequest` |
| 429 | (any) | `RateLimited` |
| ≥500 | (any) | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

The `message` is read from the JSON error body and drives the 400 refinement before the generic `InvalidRequest` fallback applies.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Mailgun-specific form fields (e.g. `o:tag`, `o:tracking`, `v:my-var`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://documentation.mailgun.com/docs/mailgun/api-reference/send/mailgun/messages>
- Regions: <https://documentation.mailgun.com/docs/mailgun/api-reference/api-overview>
- Rate limits: <https://documentation.mailgun.com/docs/mailgun/api-reference/api-overview>
