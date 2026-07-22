# WhatsApp Business Chat Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::WhatsappBusiness` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\WhatsappBusiness\WhatsappBusinessConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new WhatsappBusinessConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::WhatsappBusiness, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`WhatsappBusinessConfig(accessToken: string, phoneNumberId: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

System-user access token from Meta Business → System Users. Sent as `Authorization: Bearer <token>`. Phone Number ID identifies the sending number.

## Endpoint

`https://graph.facebook.com/{graphApiVersion}/{phoneNumberId}/messages` — the Graph API version segment is pinned via `config.graphApiVersion` (defaults to `v21.0`). JSON body.

## Narrowed input augmentations

`to` is **required** (recipient E.164 number). The baseline body is a text message: `{messaging_product: "whatsapp", recipient_type: "individual", to: <to>, type: "text", text: {body: <body>}}`. On success `providerMessageId` comes from `messages[0].id`.

`WhatsappBusinessChatSendInput` extends `ChatSendInput` with `type` (defaults to `"text"`; `text` / `template` / `interactive`), `previewUrl` (→ `text.preview_url`), `template`, `interactive`, and `context` (`{messageId}` → `{message_id}`). A `template`/`interactive` type requires the matching payload or the connector raises `InvalidRequest` (400) locally. Any further Meta Cloud API fields go via `_passthrough.body` using the wire (snake_case) keys — e.g. `template`, `interactive`, media shapes (`image`/`document`/`video`/…). WhatsApp policy requires a `template` for any first contact or messages outside the 24h customer-service window.

## Error mapping

Meta error-`code` precedence is checked before HTTP status:

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| (any) | error `code` `190` | `AuthFailed` |
| 401 | (any) | `AuthFailed` |
| (any) | error `code` `131008` / `131026` / `131047` | `InvalidRecipient` |
| (any) | error `code` `80007` / `80004` / `4` | `RateLimited` |
| 429 | (any) | `RateLimited` |
| (any) | error `code` `131051` / `131056` | `InvalidRequest` |
| 400 | (any) | `InvalidRequest` |
| (any) | error `code` `1` / `2` | `ProviderUnavailable` |
| 5xx | (any) | `ProviderUnavailable` |
| other | (any) | `Unknown` |

The error `code`/`message` are read from the JSON `error` object. Before dispatch, a request with no `to` (or a `template`/`interactive` type missing its payload) raises `InvalidRequest` (400) locally.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Meta Cloud API-specific fields (e.g. `template`, `interactive`, `context`) via `_passthrough.body`.

## Vendor docs

- API reference: https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages
- Templates: https://developers.facebook.com/docs/whatsapp/cloud-api/guides/send-message-templates
- 24h window: https://developers.facebook.com/docs/whatsapp/cloud-api/guides/send-messages
- Errors: https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
- Rate limits: https://developers.facebook.com/docs/whatsapp/cloud-api/overview#rate-limits
