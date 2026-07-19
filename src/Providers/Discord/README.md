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

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Webhook URL from a Discord channel's "Integrations" → "Webhooks". URL itself is the credential.

## Endpoint

`https://discord.com/api/webhooks/{id}/{token}`

## Rate limits

See vendor docs: <https://discord.com/developers/docs/topics/rate-limits>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Discord-specific fields (e.g. `embeds`, `components`, `allowed_mentions`) via `_passthrough.body`. The `?wait=true` query param is exposed via the `wait` narrowed-input field rather than always-on (parity divergence with TS, see harmonization audit).
