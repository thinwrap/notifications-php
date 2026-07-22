# Postmark Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Postmark` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Postmark\PostmarkConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new PostmarkConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Postmark, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`PostmarkConfig(serverToken: string, messageStream: ?string = null)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Server token from Postmark dashboard. Sent as `X-Postmark-Server-Token: <token>` header.

## Endpoint

`https://api.postmarkapp.com/email`

## Narrowed input augmentations

The wire body is hand-built in Postmark **PascalCase** from the base `EmailSendInput`: `from` (composed as `Name <addr>` when `config.senderName` is set) → `From`; `to` → `To`; `subject` → `Subject`; `cc`/`bcc` → `", "`-joined `Cc`/`Bcc`; `html`/`text` → `HtmlBody`/`TextBody`; `replyTo` → `ReplyTo`; `headers` → `Headers` (`[{Name, Value}]`); `tags` → `Tag` (**first-tag-wins** — Postmark accepts one, the rest are silently dropped per the ≥90% baseline rule); `config.messageStream` → `MessageStream`; `attachments` → `Attachments` (`{Name, Content` (base64)`, ContentType?}`, with `contentId` emitted as `ContentID: "cid:" + <id>`). On success `status` is `Sent` and `providerMessageId` comes from `MessageID`.

Soft-reject path: a **200 OK with `ErrorCode` != 0** is returned as `EmailSendResult(success: false, status: Rejected, …)` **by design — it is not thrown** (the raw body stays on `$result->raw`).

`PostmarkNarrowedInput` extends `EmailSendInput` and enumerates the Postmark-only fields (`messageStream`, `templateId`, `templateAlias`, `templateModel`, `metadata`, `trackOpens`, `trackLinks`), but at v1.0 these are **routed via `_passthrough['body']`**, not read directly by the connector. Supply them in PascalCase: `TemplateId`, `TemplateAlias`, `TemplateModel`, `Metadata`, `TrackOpens`, `TrackLinks`, and a per-send `MessageStream` override. Unlike the TS/Go siblings, the PHP connector does **not** apply a camelCase→PascalCase transform on `_passthrough['body']` (no `CasingTransform` utility in the bootstrap) — supply the exact PascalCase wire keys at the call site.

## Error mapping

| Vendor HTTP | Vendor signal (`ErrorCode`) | `ProviderCode` |
|---|---|---|
| (any non-2xx) | 10 / 405 | `AuthFailed` |
| (any non-2xx) | 300 / 406 / 411 / 412 | `InvalidRecipient` |
| (any non-2xx) | 422 / 1003 | `InvalidRequest` |
| 401 / 403 | (no matching `ErrorCode`) | `AuthFailed` |
| 429 | (no matching `ErrorCode`) | `RateLimited` |
| 5xx | (no matching `ErrorCode`) | `ProviderUnavailable` |
| other 4xx | (no matching `ErrorCode`) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

The Postmark `ErrorCode` (from the JSON error body) takes **precedence over HTTP status** when present; only when no `ErrorCode` matches does the HTTP-status fallback apply. The vendor `Message` (when present) becomes the base `$e->providerMessage`. Note the soft-reject path above (200 + `ErrorCode` != 0) does not reach this table — it returns a `Rejected` result rather than throwing.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Postmark-specific fields (e.g. `TrackOpens`, `TrackLinks`, `Metadata`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://postmarkapp.com/developer/api/email-api>
- Error codes: <https://postmarkapp.com/developer/api/overview#error-codes>
- Rate limits: <https://postmarkapp.com/developer/api/overview#rate-limiting>
