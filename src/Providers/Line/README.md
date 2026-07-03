# LINE Chat Connector (PHP)

---
providerId: line
channel: chat
novuProviderId: null
auth:
  method: bearer
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://api.line.me/v2/bot/message/push
versioning:
  vendorApiVersion: "v2"
  lastVerified: 2026-05-18
tier: 1
rateLimitDocsUrl: https://developers.line.biz/en/reference/messaging-api/#rate-limits
retryAfterSurfaced: true
notes_passthrough: |
  Forward LINE Messaging-specific fields (e.g. `messages[].quickReply`,
  `messages[].sender`) via `_passthrough.body`.
---

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Line` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Line\LineConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new LineConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::Line, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`LineConfig(channelAccessToken: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Channel access token from LINE Developers Console. Sent as `Authorization: Bearer <token>`.

## Endpoint

`https://api.line.me/v2/bot/message/push`

## Rate limits

See vendor docs: <https://developers.line.biz/en/reference/messaging-api/#rate-limits>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward LINE Messaging-specific fields (e.g. `messages[].quickReply`, `messages[].sender`) via `_passthrough.body`.
