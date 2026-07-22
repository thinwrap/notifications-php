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

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Incoming-webhook URL from a Slack app's "Incoming Webhooks" page. No additional auth — the URL itself is the credential.

## Endpoint

`https://hooks.slack.com/services/{T}/{B}/{X}` — the incoming-webhook URL is the endpoint (and the credential); the channel is fixed per webhook. Slack returns plain text `"ok"` on success (no JSON body, no message ID — `providerMessageId` is always `null`).

## Narrowed input augmentations

Standard chat input — `body` maps to Slack `text`; `to` is `null` (the webhook URL targets the channel). `SlackChatSendInput` extends `ChatSendInput` with below-baseline Slack fields: `blocks`, `attachments`, `username`, `iconEmoji` (→ `icon_emoji`), `iconUrl` (→ `icon_url`), `threadTs` (→ `thread_ts`), `mrkdwn`, `unfurlLinks` (→ `unfurl_links`), `unfurlMedia` (→ `unfurl_media`), and `linkNames` (→ `link_names`). Further fields go through `_passthrough.body`.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 / 404 | (revoked/invalid webhook) | `AuthFailed` |
| 400 | (any) | `InvalidRequest` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| — | (other) | `Unknown` |

An empty or non-`https://` `webhookUrl` raises `InvalidRequest` (400) locally before dispatch. Slack returns a plain-text error body, surfaced on `$e->cause['raw']`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Slack-specific fields (e.g. `blocks`, `attachments`, `thread_ts`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks>
- Block Kit: <https://docs.slack.dev/block-kit/>
- Rate limits: <https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks#rate_limiting>
