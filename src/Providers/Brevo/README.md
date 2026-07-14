---
providerId: brevo
channel: email
novuProviderId: sendinblue
auth:
  method: header
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://api.brevo.com/v3/smtp/email
versioning:
  vendorApiVersion: "v3"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://developers.brevo.com/docs/api-limits
retryAfterSurfaced: true
notes_passthrough: |
  Forward Brevo-specific fields (e.g. `templateId`, `params`, `tags`) via
  `_passthrough.body`. Attachment `contentId` is rejected at the wire
  layer (vendor does not support inline CIDs).
---

# Brevo Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Brevo` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Brevo\BrevoConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new BrevoConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Brevo, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`BrevoConfig(apiKey: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

API key from Brevo dashboard. Sent as `api-key: <key>` header. Vendor was formerly named Sendinblue — Novu still uses the `sendinblue` enum value (rebrand divergence).

## Endpoint

`https://api.brevo.com/v3/smtp/email`

## Rate limits

See vendor docs: <https://developers.brevo.com/docs/api-limits>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Brevo-specific fields (e.g. `templateId`, `params`, `tags`) via `_passthrough.body`. Attachment `contentId` is rejected at the wire layer (vendor does not support inline CIDs).
