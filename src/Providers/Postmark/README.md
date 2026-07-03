# Postmark Email Connector (PHP)

---
providerId: postmark
channel: email
novuProviderId: postmark
auth:
  method: header
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://api.postmarkapp.com/email
versioning:
  vendorApiVersion: "2024-08-29"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://postmarkapp.com/developer/api/overview
retryAfterSurfaced: true
notes_passthrough: |
  Forward Postmark-specific fields (e.g. `TrackOpens`, `TrackLinks`,
  `Metadata`) via `_passthrough.body`.
---

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

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Server token from Postmark dashboard. Sent as `X-Postmark-Server-Token: <token>` header.

## Endpoint

`https://api.postmarkapp.com/email`

## Rate limits

See vendor docs: <https://postmarkapp.com/developer/api/overview>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Postmark-specific fields (e.g. `TrackOpens`, `TrackLinks`, `Metadata`) via `_passthrough.body`.
