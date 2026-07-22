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

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Incoming-webhook URL from a Google Chat space → "Manage webhooks". URL embeds `key=` and `token=` query parameters — no additional auth header.

## Endpoint

`https://chat.googleapis.com/v1/spaces/{space}/messages?key={key}&token={token}`

## Narrowed input augmentations

`to` is **ignored** (the webhook is pinned to a space). `body` → wire `text` (sent with `Content-Type: application/json; charset=UTF-8`). Unlike Slack/Teams, Google Chat returns a full JSON `Message` resource on success, so `status` is `Sent` and `providerMessageId` carries the resource `name` (`spaces/<space-id>/messages/<message-id>`).

`GoogleChatChatSendInput` extends `ChatSendInput` with below-baseline fields, written verbatim in Google's camelCase wire form: `cardsV2`, `thread` (`{name}`), `fallbackText`. Any further Google Chat-specific camelCase fields go via `_passthrough['body']` (no casing transform); `_passthrough['query']` is appended to the webhook URL, whose `?key=&token=` credential params are preserved. A missing or non-`https://` `webhookUrl` raises `ConnectorError(InvalidRequest)` before any HTTP call.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 / 404 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 400 | `message` contains `space` AND (`invalid` or `not found`) | `InvalidRecipient` |
| 400 | (other) | `InvalidRequest` |
| ≥500 | (any) | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

The provider message is extracted from `error.message` in the JSON error body.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Google Chat-specific fields (e.g. `cards`, `cardsV2`, `thread`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://developers.google.com/workspace/chat/messages-overview>
- Cards v2: <https://developers.google.com/chat/api/guides/v1/messages/create#card>
- Limits: <https://developers.google.com/workspace/chat/limits>
