# Rocket.Chat Chat Connector (PHP)

---
providerId: rocket-chat
channel: chat
novuProviderId: rocket-chat
auth:
  method: header
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://{server}/api/v1/chat.postMessage
versioning:
  vendorApiVersion: "v1"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://docs.rocket.chat/reference/api/rest-api
retryAfterSurfaced: true
notes_passthrough: |
  Forward Rocket.Chat-specific fields (e.g. `alias`, `emoji`,
  `attachments`) via `_passthrough.body`.
---

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::RocketChat` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\RocketChat\RocketChatConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new RocketChatConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::RocketChat, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`RocketChatConfig(baseUrl: string, authToken: string, userId: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Personal Access Token (PAT) + User ID from Rocket.Chat → My Account → Personal Access Tokens. Sent as `X-Auth-Token` + `X-User-Id` headers (two-header auth — divergent from the TS sibling which uses incoming-webhook URL; both are documented intentional per harmonization audit).

## Endpoint

`https://{server}/api/v1/chat.postMessage`

## Rate limits

See vendor docs: <https://docs.rocket.chat/reference/api/rest-api>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Rocket.Chat-specific fields (e.g. `alias`, `emoji`, `attachments`) via `_passthrough.body`.
