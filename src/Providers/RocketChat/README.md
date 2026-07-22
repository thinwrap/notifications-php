# Rocket.Chat Chat Connector (PHP)

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

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Personal Access Token (PAT) + User ID from Rocket.Chat → My Account → Personal Access Tokens. Sent as `X-Auth-Token` + `X-User-Id` headers (two-header auth — divergent from the TS sibling which uses incoming-webhook URL; both are documented intentional per harmonization audit).

## Endpoint

`https://{server}/api/v1/chat.postMessage`

## Narrowed input augmentations

Because the PHP connector uses the REST API (two-header auth) rather than an incoming webhook, `to` is **required** and carries the channel identifier (`#channelName`, `@username`, or a raw room id) → wire `channel`; `body` → wire `text`. A missing `to`, or a non-`https://` `baseUrl` (the auth token is sent as request headers), raises `InvalidRequest` (400) locally before dispatch. On success `status` is `Sent` and `providerMessageId` comes from `message._id` (the REST path returns a message id; webhooks do not). Soft-reject path: a **200 OK with `{success: false}`** returns `ChatSendResult(success: false, status: Rejected, …)` **by design — it is not thrown**.

`RocketChatChatSendInput` extends `ChatSendInput` with below-baseline Rocket.Chat fields: `alias`, `emoji`, `avatar`, and `attachments` (Slack-compat shape, snake_case keys forwarded verbatim — Rocket.Chat already accepts that shape).

Any further Rocket.Chat-specific fields go via `_passthrough['body']`, which is forwarded verbatim (no casing transform). Expected keys include `attachments`, `alias`, `avatar`, `emoji`, `tmid`, `tshow`; attachment keys are snake_case on the wire (`author_name` / `image_url` / `thumb_url`).

## Error mapping

| Vendor HTTP | Vendor signal (`errorType`) | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 400 | `invalid-channel` | `InvalidRecipient` |
| 400 | (other) | `InvalidRequest` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| — | (unmatched, incl. 404) | `Unknown` |

The provider message is extracted from the body `error` field, falling back to `message`. A 400 whose body `errorType` is `invalid-channel` is refined to `InvalidRecipient` before the generic 400 fallback.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Rocket.Chat-specific fields (e.g. `alias`, `emoji`, `attachments`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://docs.rocket.chat/use-rocket.chat/workspace-administration/integrations>
- REST API: <https://docs.rocket.chat/reference/api/rest-api>
