# Microsoft Teams Chat Connector (PHP)

---
providerId: ms-teams
channel: chat
novuProviderId: msteams
auth:
  method: webhook-url
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://{tenant}.webhook.office.com/webhookb2/{id}/IncomingWebhook/{token}
versioning:
  vendorApiVersion: "2024"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook
retryAfterSurfaced: true
notes_passthrough: |
  Forward Adaptive Card payloads (`type: "AdaptiveCard"`, `attachments`)
  via `_passthrough.body`. PHP connector validates that the response body
  is exactly `"1"` and throws on any other shape (stricter than TS
  sibling).
---

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Msteams` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Msteams\MsteamsConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new MsteamsConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::Msteams, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`MsteamsConfig(webhookUrl: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Incoming-webhook URL from a Teams channel → "Connectors" → "Incoming Webhook". URL itself is the credential.

## Endpoint

`https://{tenant}.webhook.office.com/webhookb2/{id}/IncomingWebhook/{token}`

## Rate limits

See vendor docs: <https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Adaptive Card payloads (`type: "AdaptiveCard"`, `attachments`) via `_passthrough.body`. PHP connector validates that the response body is exactly `"1"` and throws on any other shape (stricter than TS sibling).
