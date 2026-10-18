# Resend Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Resend` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Resend\ResendConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new ResendConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Resend, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`ResendConfig(apiKey: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Generate an API key in Resend dashboard. Sent as `Authorization: Bearer <key>`.

## Endpoint

`https://api.resend.com/emails`

## Narrowed input augmentations

The wire body uses Resend's lowercase shape: `from` (composed as `Name <addr>` when `config.senderName` is set — quoted when the name holds RFC 5322 specials, RFC 2047 encoded-words when it is non-ASCII) → `from`; `to` → `to` (one-element array); `subject` → `subject`; `html`/`text` → `html`/`text`; `cc`/`bcc` → `cc`/`bcc`; `replyTo` → `reply_to` (one-element array); `headers` → `headers`; `tags` → `tags` as `[{name: "tag", value: <t>}]` (Resend allows only ASCII letters, digits, `_` and `-`, max 256, so any other tag is `InvalidRequest` before sending); `attachments` → `attachments` (`{filename, content` (base64)`, content_type?, content_id?}` — `contentId` becomes `content_id`, so `<img src="cid:…">` resolves). On success `status` is `Sent` and `providerMessageId` comes from the response `id`.

`ResendNarrowedInput` extends `EmailSendInput` with a single below-baseline field, `idempotencyKey`, which is forwarded as the `Idempotency-Key` request header.

Any further Resend-specific fields go via `_passthrough['body']`, which merges verbatim — supply the exact snake_case wire keys directly (e.g. `scheduled_at`). Unlike the TS/Go siblings, the PHP connector does **not** apply a camelCase→snake_case transform on `_passthrough`. Use `_passthrough['headers']` for any additional headers.

## Error mapping

| Vendor HTTP | Vendor signal (body `name` / `message`) | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| (any) | name `missing_api_key` / `invalid_api_key` / `restricted_api_key` | `AuthFailed` |
| (any) | name `rate_limit_exceeded` | `RateLimited` |
| (any) | name `application_error` / `internal_server_error` | `ProviderUnavailable` |
| (any) | name `validation_error`, `message` matches `recipient` / `email address` | `InvalidRecipient` |
| (any) | name `validation_error`, (other message) | `InvalidRequest` |
| 429 | (no matching name) | `RateLimited` |
| 5xx | (no matching name) | `ProviderUnavailable` |
| 400 / 422 | (no matching name) `message` matches `recipient` / `email address` | `InvalidRecipient` |
| 400 / 422 | (no matching name) (other) | `InvalidRequest` |
| 413 | (no matching name) | `InvalidRequest` |
| — | (unmatched, incl. 404) | `Unknown` |

Precedence: HTTP `401`/`403` is the authoritative auth signal and wins first (Resend returns `401` with name `validation_error` for a bad key, which would otherwise be misread); the body `name` layer is applied next when present; the remaining HTTP-status rows apply only when no `name` matches. The vendor `message` (when present) becomes the base `$e->providerMessage`, and recipient classification is a case-insensitive match on `recipient` / `email address` in that message.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Resend-specific fields (e.g. `tags`, `headers`, `scheduled_at`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://resend.com/docs/api-reference/emails/send-email>
- Idempotency: <https://resend.com/docs/dashboard/emails/idempotency-keys>
- Rate limits: <https://resend.com/docs/api-reference/rate-limit>
