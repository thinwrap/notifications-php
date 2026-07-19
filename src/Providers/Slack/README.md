# Slack Chat Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Slack` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Slack\SlackConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new SlackConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::Slack, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`SlackConfig(webhookUrl: string)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Incoming-webhook URL from a Slack app's "Incoming Webhooks" page. No additional auth — the URL itself is the credential.

## Endpoint

`https://hooks.slack.com/services/{T}/{B}/{X}`

## Rate limits

See vendor docs: <https://api.slack.com/messaging/webhooks#rate_limiting>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Slack-specific fields (e.g. `blocks`, `attachments`, `thread_ts`) via `_passthrough.body`.
