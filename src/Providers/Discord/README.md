# Discord Chat Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Discord` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Discord\DiscordConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new DiscordConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::Discord, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`DiscordConfig(webhookUrl: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Webhook URL from a Discord channel's "Integrations" → "Webhooks". URL itself is the credential.

## Endpoint

`https://discord.com/api/webhooks/{id}/{token}` — the webhook URL is the endpoint (and the credential). The connector always appends `?wait=true` so Discord returns the created message ID in the response body, and `?thread_id=<id>` when `threadId` is set.

## Narrowed input augmentations

Standard chat input — `body` is the message content; `to` is `null` (the webhook URL targets the channel). `DiscordChatSendInput` extends `ChatSendInput` with below-baseline Discord fields: `embeds`, `components`, `username`, `avatarUrl` (→ `avatar_url`), `tts`, `flags`, `allowedMentions` (→ `allowed_mentions`), `threadName` (→ `thread_name`), and `threadId` (→ `?thread_id` query param). Further webhook fields go through `_passthrough.body`.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 / 404 | (revoked/invalid webhook) | `AuthFailed` |
| 400 | (any) | `InvalidRequest` |
| 429 | `retry_after` in body | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| — | (other) | `Unknown` |

An empty or non-`https://` `webhookUrl` raises `InvalidRequest` (400) locally before dispatch.

Discord emits a retry-after signal on two channels: the body `retry_after` (seconds, float) takes precedence; the header `Retry-After` (historically milliseconds) is used as a heuristic fallback (values > 1000 are treated as ms). Parsed seconds land on `$e->cause['retryAfterSeconds']` (raw body value on `$e->cause['retryAfterBody']`, raw header on `$e->cause['retryAfter']`) and are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Discord-specific fields (e.g. `embeds`, `components`, `allowed_mentions`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://discord.com/developers/docs/resources/webhook#execute-webhook>
- Rate limits: <https://discord.com/developers/docs/topics/rate-limits>
