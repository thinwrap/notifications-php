# Mailtrap Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Mailtrap` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Mailtrap\MailtrapConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new MailtrapConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Mailtrap, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`MailtrapConfig(apiToken: string, mode: MailtrapMode, inboxId: ?string = null, from: ?string = null, senderName: ?string = null)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API token from Mailtrap dashboard. Sent as `Authorization: Bearer <token>`. Sandbox endpoint at `sandbox.api.mailtrap.io`.

## Endpoint

`https://send.api.mailtrap.io/api/send` (production). In sandbox mode the endpoint is `https://sandbox.api.mailtrap.io/api/send/{inboxId}`. JSON request body. The mode is validated at construction time (sandbox requires a non-empty `inboxId`; production forbids one).

## Narrowed input augmentations

Standard email input applies and maps to Mailtrap's wire shape: `from` (+ config `senderName`) → `from` (`{email, name?}`), `to` → `to` (`[{email}]`), `subject` → `subject`, `cc`/`bcc` → `cc`/`bcc` (`[{email}]`), `replyTo` → `reply_to` (a single `{email}` object), `html`/`text` → `html`/`text`, `headers` → `headers` (flat map), and `attachments` → `attachments` (`{filename, content, type?}`, base64; a `contentId` adds `content_id` + `disposition: "inline"`). `status` is `Sent`; `providerMessageId` comes from `message_ids[0]`. A non-empty `tags` is **rejected pre-flight** with `ConnectorError(InvalidRequest)` — Mailtrap has no tags at v1.0 (use `MailtrapNarrowedInput` `category`).

`MailtrapNarrowedInput` adds typed Mailtrap fields: `category` → `category`, `customVariables` → `custom_variables`, `templateUuid` → `template_uuid`, `templateVariables` → `template_variables`. A template send (a `template_uuid` in the final body, typed or from `_passthrough`) drops `subject` / `text` / `html`, because Mailtrap's template request has none (the template supplies them), and a `category` with it is rejected pre-flight with `InvalidRequest` (set the category on the template).

Other provider-specific fields ride in `_passthrough['body']` in Mailtrap's snake_case wire form (written directly — no PHP-side casing transform) and win over the typed fields.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| ≥500 | (any) | `ProviderUnavailable` |
| 422 / 400 | any `errors[]` entry matches `recipient` / `email` / `to ` / `from address` | `InvalidRecipient` |
| 422 / 400 | (other) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

Vendor error strings from `errors[]` are joined into `$e->providerMessage` and preserved on `$e->cause['raw']`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Mailtrap-specific fields (e.g. `category`, `custom_variables`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://docs.mailtrap.io/developers>
- Sandbox guide: <https://docs.mailtrap.io/email-sandbox/setup/sandbox-api-integration>
