# SendGrid Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Sendgrid` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new SendgridConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Sendgrid, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`SendgridConfig(apiKey: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

API key from SendGrid dashboard. Sent as `Authorization: Bearer <key>`.

## Endpoint

`https://api.sendgrid.com/v3/mail/send` — single global endpoint, no regional clusters.

## Narrowed input augmentations

The standard email input applies as-is: `from`, `to`, `cc`, `bcc`, `replyTo`, `subject`, `text`, `html`, `attachments`, `headers`, and `tags` (mapped to SendGrid `categories`). `SendgridNarrowedInput` adds typed SendGrid fields, each sent under its v3 wire name: `templateId` → `template_id`, `dynamicTemplateData` → `personalizations[0].dynamic_template_data`, `sendAt` (unix seconds, at most 72 h ahead) → `send_at`, `batchId` → `batch_id`, `asm` (`['group_id' => int, 'groups_to_display' => [...]]`) → `asm`, `mailSettings` → `mail_settings`, `trackingSettings` → `tracking_settings`, `ipPoolName` → `ip_pool_name`, and `categories` → `categories` (merged with `tags`, de-duplicated; more than 10, or one longer than 255 characters, is `InvalidRequest` before sending, since SendGrid would reject the whole send). On a template send (`template_id`), an empty `subject` is left out so the dynamic template's own subject applies; a non-empty one is sent. The settings arrays are sent as given, so use SendGrid's snake_case keys (`['click_tracking' => ['enable' => false]]`). Any other v3 field goes through `_passthrough.body` using snake_case wire keys, and `_passthrough.body` wins on conflict.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 400 | first `errors[].message` contains "email" | `InvalidRecipient` |
| 400 | (other) | `InvalidRequest` |
| 413 | (any) | `InvalidRequest` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

The 400 → `InvalidRecipient` branch is a case-insensitive substring check for `email` on the first `errors[].message` in the JSON error body.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward SendGrid v3-specific fields (e.g. `dynamic_template_data`, `mail_settings`, `tracking_settings`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://www.twilio.com/docs/sendgrid/api-reference/mail-send/mail-send>
- Error codes: <https://www.twilio.com/docs/sendgrid/api-reference/how-to-use-the-sendgrid-v3-api/responses>
- Rate limits: <https://www.twilio.com/docs/sendgrid/api-reference/how-to-use-the-sendgrid-v3-api/rate-limits>
