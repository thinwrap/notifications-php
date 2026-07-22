# Telegram Chat Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Telegram` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Telegram\TelegramConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new TelegramConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::Telegram, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`TelegramConfig(botToken: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Bot token from @BotFather. Token is part of the URL path (`https://api.telegram.org/bot<token>/sendMessage`) — no Authorization header.

## Endpoint

`https://api.telegram.org/bot{botToken}/sendMessage` — single global endpoint, JSON body.

## Narrowed input augmentations

`to` is **required** (`chat_id` numeric ID or `@channelname`) → wire `chat_id`; `body` → wire `text`. On success `providerMessageId` comes from `result.message_id` (a JSON number, formatted as a string).

`TelegramChatSendInput` extends `ChatSendInput` with below-baseline Telegram fields, written to the wire in snake_case: `parseMode` (`parse_mode`), `entities`, `disableNotification` (`disable_notification`), `protectContent` (`protect_content`), `replyParameters` (`reply_parameters`), `replyMarkup` (`reply_markup`), `linkPreviewOptions` (`link_preview_options`), `messageThreadId` (`message_thread_id`). Any further Telegram-specific snake_case fields go via `_passthrough.body`.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 400 | `description` matches `chat not found` / `user_id_invalid` / `peer_id_invalid` / `chat_id_invalid` | `InvalidRecipient` |
| 400 | (other) | `InvalidRequest` |
| 5xx | (any) | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

The `description` is read from the JSON error body. Telegram also emits a soft-rejection path — HTTP 200 with `ok: false` — which is re-routed through the same mapper using the body-supplied `error_code` (or 400 as a defensive fallback). Before dispatch, a request with no `to` raises `InvalidRequest` (400) locally.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`. **Outlier**: Telegram carries Retry-After in the JSON body at `parameters.retry_after` (an int), not an HTTP header — that value is both `$e->cause['retryAfter']` and `$e->cause['retryAfterSeconds']`, and is woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Telegram-specific fields (e.g. `parse_mode`, `reply_markup`, `disable_web_page_preview`) via `_passthrough.body`. **Outlier**: Retry-After lives in JSON body at `parameters.retry_after` (int) on 429 — `$e->cause['retryAfter']` is int, not string.

## Vendor docs

- API reference: https://core.telegram.org/bots/api#sendmessage
- Errors: https://core.telegram.org/api/errors
- Rate limits: https://core.telegram.org/bots/faq#my-bot-is-hitting-limits-how-do-i-avoid-this
