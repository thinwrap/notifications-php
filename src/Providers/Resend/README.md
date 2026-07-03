# Resend Email Connector (PHP)

---
providerId: resend
channel: email
novuProviderId: resend
auth:
  method: bearer
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://api.resend.com/emails
versioning:
  vendorApiVersion: "2024-09-01"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://resend.com/docs/api-reference/rate-limit
retryAfterSurfaced: true
notes_passthrough: |
  Forward Resend-specific fields (e.g. `tags`, `headers`, `scheduled_at`)
  via `_passthrough.body`.
---

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

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Generate an API key in Resend dashboard. Sent as `Authorization: Bearer <key>`.

## Endpoint

`https://api.resend.com/emails`

## Rate limits

See vendor docs: <https://resend.com/docs/api-reference/rate-limit>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Resend-specific fields (e.g. `tags`, `headers`, `scheduled_at`) via `_passthrough.body`.
