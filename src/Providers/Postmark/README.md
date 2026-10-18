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

`PostmarkConfig(serverToken: string, from: string, senderName: ?string = null, messageStream: ?string = null)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Server token from Postmark dashboard. Sent as `X-Postmark-Server-Token: <token>` header.

## Endpoint

`https://api.postmarkapp.com/email`, or `https://api.postmarkapp.com/email/withTemplate` for a template send (see below).

## Narrowed input augmentations

The wire body is hand-built in Postmark **PascalCase** from the base `EmailSendInput`: `from` (composed as `Name <addr>` when `config.senderName` is set — quoted when the name holds RFC 5322 specials, RFC 2047 encoded-words when it is non-ASCII) → `From`; `to` → `To`; `subject` → `Subject`; `cc`/`bcc` → `", "`-joined `Cc`/`Bcc`; `html`/`text` → `HtmlBody`/`TextBody`; `replyTo` → `ReplyTo`; `headers` → `Headers` (`[{Name, Value}]`); `tags` → `Tag` (**first-tag-wins** — Postmark accepts one, the rest are silently dropped per the ≥90% baseline rule); `config.messageStream` → `MessageStream`; `attachments` → `Attachments` (`{Name, Content` (base64)`, ContentType?}`, with `contentId` emitted as `ContentID: "cid:" + <id>`). On success `status` is `Sent` and `providerMessageId` comes from `MessageID`.

Soft-reject path: a **200 OK with `ErrorCode` != 0** is returned as `EmailSendResult(success: false, status: Rejected, …)` **by design — it is not thrown** (the raw body stays on `$result->raw`).

`PostmarkNarrowedInput` extends `EmailSendInput` with typed Postmark fields, each sent under its Postmark name: `messageStream` → `MessageStream` (overrides `config.messageStream` for this send), `metadata` → `Metadata`, `trackOpens` → `TrackOpens`, `trackLinks` → `TrackLinks` (`None` / `HtmlAndText` / `HtmlOnly` / `TextOnly`), `templateId` (an integer; a non-numeric string is sent as `TemplateAlias`, as postmark-php does) → `TemplateId`, `templateAlias` → `TemplateAlias`, `templateModel` → `TemplateModel`. Setting both `templateId` and `templateAlias`, or a `trackLinks` outside Postmark's case-sensitive enum, is `InvalidRequest` before sending.

**Templates.** Postmark sends templates only through `POST /email/withTemplate`. When the final body carries a `TemplateId` or a non-empty `TemplateAlias` (from `PostmarkNarrowedInput` or `_passthrough['body']`), the connector posts there instead of `/email`, drops `Subject` / `HtmlBody` / `TextBody` (the template generates them from `TemplateModel`), and sends `TemplateModel: {}` when it is missing, null or empty. Empty `Metadata` is sent as `{}`.

`_passthrough['body']` wins over the typed fields. Unlike the TS/Go siblings, the PHP connector does **not** apply a camelCase→PascalCase transform on `_passthrough['body']` — supply the exact PascalCase wire keys at the call site.

## Error mapping

| Vendor HTTP | Vendor signal (`ErrorCode`) | `ProviderCode` |
|---|---|---|
| (any non-2xx) | 10 (bad token), 412 (pending account: recipients must share the From domain), 413 (account not approved), 422 (invalid Server or Account), 1480 (IP not authorized), 405 (legacy) | `AuthFailed` |
| (any non-2xx) | 406 (inactive recipient); 300 when the message names a quoted `'To'` / `'Cc'` / `'Bcc'` field (e.g. "Invalid 'To' address") | `InvalidRecipient` |
| (any non-2xx) | 300 (send validation otherwise, incl. a From / Reply-To address and "Zero recipients specified"), 402, 403, 410, 411 (attachment type not allowed), 1101 (template), 1235 / 1236 (stream), 1003 (legacy) | `InvalidRequest` |
| 401 / 403 | (no matching `ErrorCode`) | `AuthFailed` |
| 429 | (no matching `ErrorCode`) | `RateLimited` |
| 5xx | (no matching `ErrorCode`) | `ProviderUnavailable` |
| other 4xx | (no matching `ErrorCode`) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

The Postmark `ErrorCode` (from the JSON error body) takes **precedence over HTTP status** when present; only when no `ErrorCode` matches does the HTTP-status fallback apply. The vendor `Message` (when present) becomes the base `$e->providerMessage`. Note the soft-reject path above (200 + `ErrorCode` != 0) does not reach this table — it returns a `Rejected` result rather than throwing.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward other Postmark fields (e.g. `InlineCss` on a template send) via `_passthrough.body`, in PascalCase.

## Vendor docs

- API reference: <https://postmarkapp.com/developer/api/email-api>
- Templates (`/email/withTemplate`): <https://postmarkapp.com/developer/api/templates-api>
- Error codes: <https://postmarkapp.com/developer/api/overview#error-codes>
- Rate limits: <https://postmarkapp.com/developer/api/overview#rate-limiting>
