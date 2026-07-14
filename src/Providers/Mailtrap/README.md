---
providerId: mailtrap
channel: email
novuProviderId: mailtrap
auth:
  method: bearer
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://send.api.mailtrap.io/api/send
  regional:
    - sandbox
    - production
versioning:
  vendorApiVersion: "2024"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://api-docs.mailtrap.io/docs/mailtrap-api-docs/5tjdeg9545058-send-email
retryAfterSurfaced: true
notes_passthrough: |
  Forward Mailtrap-specific fields (e.g. `category`, `custom_variables`)
  via `_passthrough.body`.
---

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

`MailtrapConfig(apiToken: string, sandbox: bool = false, inboxId: ?int = null)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

API token from Mailtrap dashboard. Sent as `Authorization: Bearer <token>`. Sandbox endpoint at `sandbox.api.mailtrap.io`.

## Endpoint

`https://send.api.mailtrap.io/api/send`

## Regional endpoints

`sandbox`, `production`

## Rate limits

See vendor docs: <https://api-docs.mailtrap.io/docs/mailtrap-api-docs/5tjdeg9545058-send-email>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Mailtrap-specific fields (e.g. `category`, `custom_variables`) via `_passthrough.body`.
