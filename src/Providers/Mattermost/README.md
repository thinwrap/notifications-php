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

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Incoming-webhook URL from a Mattermost team → "Integrations" → "Incoming Webhooks". URL itself is the credential.

## Endpoint

`https://{server}/hooks/{token}`

## Narrowed input augmentations

`to` is **optional**: it is intentionally `null` on the base input because the webhook posts to its bound default channel; a channel override is available but is server-config gated. `body` → wire `text`. On success `status` is `Sent`. Mattermost Incoming Webhooks return the plain text `ok` (no JSON, no message id), so `providerMessageId` is **always `null`** and `raw` carries the response text.

`MattermostChatSendInput` extends `ChatSendInput` with below-baseline Mattermost fields. The wire body is snake_case, so the connector maps the camelCase fields field-by-field: `iconUrl` → `icon_url`, `iconEmoji` → `icon_emoji`; `username`, `channel`, `props`, and `type` keep their names. Mattermost adopted Slack's legacy attachment format, so `attachments` (`list<SlackCompatAttachment>`) is mapped to snake_case wire keys such as `author_name` / `image_url` inside the connector. `username`, `iconUrl`, `iconEmoji`, and `channel` are silently ignored unless the server admin enabled the corresponding override integrations.

Any further Mattermost-specific fields go via `_passthrough['body']`, which merges verbatim — supply snake_case wire keys directly (e.g. `icon_url`, `author_name`, `image_url`).

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 / 404 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 400 | (any) | `InvalidRequest` |
| 5xx | (any) | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

Classification is driven purely by HTTP status; the plain-text response body (when present) becomes the base `$e->providerMessage`. Before dispatch, an empty `webhookUrl` or a non-`https://` `webhookUrl` raises `InvalidRequest` (400) locally.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Mattermost-specific fields (e.g. `username`, `icon_url`, `attachments`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://developers.mattermost.com/integrate/webhooks/incoming/>
- Message attachments: <https://developers.mattermost.com/integrate/reference/message-attachments/>
