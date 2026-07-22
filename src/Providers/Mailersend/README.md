# MailerSend Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Mailersend` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Mailersend\MailersendConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new MailersendConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Mailersend, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`MailersendConfig(apiToken: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API token from MailerSend dashboard. Sent as `Authorization: Bearer <token>`.

## Endpoint

`https://api.mailersend.com/v1/email`

## Narrowed input augmentations

Standard email input applies and maps to MailerSend's wire shape: `from` (+ config `senderName`) → `from` (`{email, name?}`), `to` → `to` (`[{email}]`), `subject` → `subject`, `cc`/`bcc` → `cc`/`bcc` (`[{email}]`), `replyTo` → `reply_to` (a single `{email}` object), `html`/`text` → `html`/`text`, `tags` → `tags` (**max 5**; extras truncated), and `attachments` → `attachments` (`{filename, content, content_type?}`, base64; a `contentId` adds `id` + `disposition: "inline"`). The 202 Accepted has an empty body, so `status` is `Queued`, `providerMessageId` comes from the `X-Message-Id` response header, and `raw` is `{messageId}`.

Provider-specific fields ride in `_passthrough['body']` in MailerSend's snake_case wire form (written directly — no PHP-side casing transform): e.g. `template_id`, `send_at`, `in_reply_to`, `personalization`.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| ≥500 | (any) | `ProviderUnavailable` |
| 422 | an `errors` key starts with `to.` / `cc.` / `bcc.`, or the key `from.email` | `InvalidRecipient` |
| 422 | (no matching field key) `message` matches `recipient` / `email address` | `InvalidRecipient` |
| 422 | (other) | `InvalidRequest` |
| — | (unmatched, incl. 400 / 404) | `Unknown` |

The `422` field-key detection and the message-phrase fallback both refine to `InvalidRecipient` before the generic `InvalidRequest`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward MailerSend-specific fields (e.g. `tags`, `personalization`, `variables`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://developers.mailersend.com/api/v1/email.html>
- Rate limits: <https://developers.mailersend.com/general.html#api-response>
