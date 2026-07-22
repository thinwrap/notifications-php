# Brevo Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Brevo` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Brevo\BrevoConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new BrevoConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Brevo, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`BrevoConfig(apiKey: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API key from Brevo dashboard. Sent as `api-key: <key>` header. (Brevo was formerly named Sendinblue.)

## Endpoint

`https://api.brevo.com/v3/smtp/email`

## Narrowed input augmentations

Standard email input applies and maps to Brevo's wire shape: `from` (+ config `senderName`) → `sender` (`{email, name?}`), `to` → `to` (`[{email}]`), `subject` → `subject`, `cc`/`bcc` → `cc`/`bcc` (`[{email}]`), `html`/`text` → `htmlContent`/`textContent`, `replyTo` → `replyTo` (`{email}`), `headers` → `headers` (flat map), `tags` → `tags`, and `attachments` → `attachment` (`{name, content}`, base64). `status` is `Sent`; `providerMessageId` comes from `messageId`.

Brevo exposes no narrowed input class — provider-specific fields ride verbatim in `_passthrough['body']` (no casing transform; Brevo's wire shape is already camelCase): e.g. `templateId`, `params`, `messageVersions`, `scheduledAt`, `batchId`. **Pre-flight guard:** any attachment with a `contentId` raises `ConnectorError(InvalidRequest)` before any HTTP call — Brevo's `/v3/smtp/email` has no inline cid mechanism.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 402 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| 400 | `message` matches `recipient` / `to[` / `email address` / `sender` | `InvalidRecipient` |
| 400 | (other) | `InvalidRequest` |
| 404 | (any) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

The `message` is read from the JSON error body and drives the 400 refinement before the generic `InvalidRequest` fallback applies.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Brevo-specific fields (e.g. `templateId`, `params`, `tags`) via `_passthrough.body`. Attachment `contentId` is rejected at the wire layer (vendor does not support inline CIDs).

## Vendor docs

- API reference: <https://developers.brevo.com/reference/send-transac-email>
- Templates: <https://developers.brevo.com/docs/send-a-transactional-email>
- Rate limits: <https://developers.brevo.com/docs/api-limits>
