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

Standard email input applies and maps to MailerSend's wire shape: `from` (+ config `senderName`) → `from` (`{email, name?}`), `to` → `to` (`[{email}]`), `subject` → `subject`, `cc`/`bcc` → `cc`/`bcc` (`[{email}]`), `replyTo` → `reply_to` (a single `{email}` object), `html`/`text` → `html`/`text`, `tags` → `tags` (**max 5**; extras truncated), `attachments` → `attachments` (`{filename, content}`, base64; a `contentId` adds `id` + `disposition: "inline"`; MailerSend has no content-type field and derives the type from the filename, so `contentType` is not sent), and `headers` → `headers` (a list of `{name, value}`). The 202 Accepted normally has an empty body, so `status` is `Queued`, `providerMessageId` comes from the `X-Message-Id` response header, and `raw` is `{messageId}`. When some recipients are suppressed, the 202 carries `{message, warnings: [{type: "SOME_SUPPRESSED", recipients, …}]}`; the send still succeeds and those keys are merged into `raw` next to `messageId`. When **all** recipients are suppressed (`ALL_SUPPRESSED`, no `X-Message-Id`), the email is not sent and the connector throws `InvalidRecipient`.

`MailersendNarrowedInput` adds typed MailerSend fields: `templateId` → `template_id`, `personalization` → `personalization` (`[['email' => …, 'data' => [...]]]`), `sendAt` (unix seconds, at most 72 h ahead) → `send_at`, `precedenceBulk` → `precedence_bulk`, and `settings` (`trackClicks` / `trackOpens` / `trackContent`, or MailerSend's own `track_clicks` / `track_opens` / `track_content`) → `settings` in snake_case; values must be booleans and any other key is `InvalidRequest` before sending. On a template send (`template_id`), an empty `subject` is left out so the template's default subject applies; a non-empty one overrides it. Its `variables` field is deprecated and never sent: MailerSend removed simple personalization from the API — use `personalization`.

Other provider-specific fields ride in `_passthrough['body']` in MailerSend's snake_case wire form (written directly — no PHP-side casing transform) and win over the typed fields: e.g. `in_reply_to`.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| ≥500 | (any) | `ProviderUnavailable` |
| 422 | an `errors` key starts with `to.` / `cc.` / `bcc.`, or the key `from.email` | `InvalidRecipient` |
| 422 | (no matching field key) `message` matches `recipient` / `email address` | `InvalidRecipient` |
| 422 | (other) | `InvalidRequest` |
| 202 | `warnings[].type` is `ALL_SUPPRESSED` (email not sent) | `InvalidRecipient` |
| — | (unmatched, incl. 400 / 404) | `Unknown` |

The `202` row keeps `statusCode: 202` and the response body on `$e->cause['raw']`. The `422` field-key detection and the message-phrase fallback both refine to `InvalidRecipient` before the generic `InvalidRequest`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward other MailerSend fields (e.g. `in_reply_to`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://developers.mailersend.com/api/v1/email.html>
- Rate limits: <https://developers.mailersend.com/general.html#api-response>
