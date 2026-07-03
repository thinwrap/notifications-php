# MailerSend Email Connector (PHP)

---
providerId: mailersend
channel: email
novuProviderId: mailersend
auth:
  method: bearer
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://api.mailersend.com/v1/email
versioning:
  vendorApiVersion: "v1"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://developers.mailersend.com/general.html#api-response
retryAfterSurfaced: true
notes_passthrough: |
  Forward MailerSend-specific fields (e.g. `tags`, `personalization`,
  `variables`) via `_passthrough.body`.
---

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

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

API token from MailerSend dashboard. Sent as `Authorization: Bearer <token>`.

## Endpoint

`https://api.mailersend.com/v1/email`

## Rate limits

See vendor docs: <https://developers.mailersend.com/general.html#api-response>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward MailerSend-specific fields (e.g. `tags`, `personalization`, `variables`) via `_passthrough.body`.
