# Mattermost Chat Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Mattermost` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Mattermost\MattermostConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new MattermostConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::Mattermost, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`MattermostConfig(webhookUrl: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Incoming-webhook URL from a Mattermost team → "Integrations" → "Incoming Webhooks". URL itself is the credential.

## Endpoint

`https://{server}/hooks/{token}`

## Rate limits

See vendor docs: <https://developers.mattermost.com/integrate/webhooks/incoming/>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Mattermost-specific fields (e.g. `username`, `icon_url`, `attachments`) via `_passthrough.body`.
