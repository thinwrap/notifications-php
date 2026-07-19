# Google Chat Chat Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::GoogleChat` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\GoogleChat\GoogleChatConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new GoogleChatConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::GoogleChat, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`GoogleChatConfig(webhookUrl: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Incoming-webhook URL from a Google Chat space → "Manage webhooks". URL embeds `key=` and `token=` query parameters — no additional auth header.

## Endpoint

`https://chat.googleapis.com/v1/spaces/{space}/messages?key={key}&token={token}`

## Rate limits

See vendor docs: <https://developers.google.com/chat/api/guides/quotas>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Google Chat-specific fields (e.g. `cards`, `cardsV2`, `thread`) via `_passthrough.body`.
