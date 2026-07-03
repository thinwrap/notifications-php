# SendGrid Email Connector (PHP)

---
providerId: sendgrid
channel: email
novuProviderId: sendgrid
auth:
  method: bearer
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://api.sendgrid.com/v3/mail/send
versioning:
  vendorApiVersion: "v3"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://www.twilio.com/docs/sendgrid/api-reference/how-to-use-the-sendgrid-v3-api/rate-limits
retryAfterSurfaced: true
notes_passthrough: |
  Forward SendGrid v3-specific fields (e.g. `dynamic_template_data`,
  `mail_settings`, `tracking_settings`) via `_passthrough.body`.
---

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

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

API key from SendGrid dashboard. Sent as `Authorization: Bearer <key>`.

## Endpoint

`https://api.sendgrid.com/v3/mail/send`

## Rate limits

See vendor docs: <https://www.twilio.com/docs/sendgrid/api-reference/how-to-use-the-sendgrid-v3-api/rate-limits>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward SendGrid v3-specific fields (e.g. `dynamic_template_data`, `mail_settings`, `tracking_settings`) via `_passthrough.body`.
