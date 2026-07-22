# LINE Chat Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Line` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Line\LineConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new LineConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::Line, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`LineConfig(channelAccessToken: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Channel access token from LINE Developers Console. Sent as `Authorization: Bearer <token>`.

## Endpoint

`https://api.line.me/v2/bot/message/push`

## Narrowed input augmentations

`to` is **required** (a `userId` / `groupId` / `roomId` from a LINE webhook event) → wire `to`; a missing `to` raises `ConnectorError(InvalidRequest)` before any HTTP call. `body` is synthesized into a single text message (`messages: [{ type: "text", text: <body> }]`). On success `status` is `Sent` and `providerMessageId` comes from `sentMessages[0].id`.

`LineChatSendInput` extends `ChatSendInput` with below-baseline fields, written verbatim in LINE's camelCase wire form: `messages` (1–5 message objects; when set it overrides the synthesized text message and `body` is ignored — parallel to WhatsApp Business template/interactive override), `notificationDisabled`, `customAggregationUnits`. Any further LINE-specific camelCase fields go via `_passthrough['body']` (no casing transform); a `messages` array supplied via passthrough likewise overrides the synthesized text.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| ≥500 | (any) | `ProviderUnavailable` |
| 400 | top-level `message` matches `not found` / `userid`, **or** any `details[].message` matches `invalid user` | `InvalidRecipient` |
| 400 | (other) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

The top-level `message` is surfaced as `$e->providerMessage`; per-property specifics (`details`) are readable from `$e->cause['raw']`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward LINE Messaging-specific fields (e.g. `messages[].quickReply`, `messages[].sender`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://developers.line.biz/en/reference/messaging-api/#send-push-message>
- Errors: <https://developers.line.biz/en/reference/messaging-api/#error-responses>
- Flex Messages: <https://developers.line.biz/en/docs/messaging-api/using-flex-messages/>
- Rate limits: <https://developers.line.biz/en/reference/messaging-api/#rate-limits>
