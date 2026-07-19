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

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

Bot token from @BotFather. Token is part of the URL path (`https://api.telegram.org/bot<token>/sendMessage`) — no Authorization header.

## Endpoint

`https://api.telegram.org/bot{botToken}/sendMessage`

## Rate limits

See vendor docs: <https://core.telegram.org/bots/faq#my-bot-is-hitting-limits-how-do-i-avoid-this>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Telegram-specific fields (e.g. `parse_mode`, `reply_markup`, `disable_web_page_preview`) via `_passthrough.body`. **Outlier**: Retry-After lives in JSON body at `parameters.retry_after` (int) on 429 — `$e->cause['retryAfter']` is int, not string.
