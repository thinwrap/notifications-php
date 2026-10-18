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

`WhatsappBusinessConfig(accessToken: string, phoneNumberId: string, graphApiVersion: string = 'v21.0')`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

System-user access token from Meta Business → System Users. Sent as `Authorization: Bearer <token>`. Phone Number ID identifies the sending number.

## Endpoint

`https://graph.facebook.com/{graphApiVersion}/{phoneNumberId}/messages` — the Graph API version segment is pinned via `config.graphApiVersion` (defaults to `v21.0`). JSON body.

## Narrowed input augmentations

`to` is **required** (recipient E.164 number). The baseline body is a text message: `{messaging_product: "whatsapp", recipient_type: "individual", to: <to>, type: "text", text: {body: <body>}}`. On success `providerMessageId` comes from `messages[0].id`.

`WhatsappBusinessChatSendInput` extends `ChatSendInput` with `type` (defaults to `"text"`; `text` / `template` / `interactive`), `previewUrl` (→ `text.preview_url`), `template`, `interactive`, and `context` (`{messageId}` → `{message_id}`). A `template`/`interactive` type requires the matching payload or the connector raises `InvalidRequest` (400) locally. Any further Meta Cloud API fields go via `_passthrough.body` using the wire (snake_case) keys — e.g. `template`, `interactive`, media shapes (`image`/`document`/`video`/…). WhatsApp policy requires a `template` for any first contact or messages outside the 24h customer-service window.

## Error mapping

Meta's error `code` is checked first; the HTTP status applies only when the code is not listed.

| Error `code` (Cloud API) | `ProviderCode` |
|---|---|
| `0`, `3`, `10`, `190` (expired token), `200`–`299`, `368`, `131005`, `131031` | `AuthFailed` |
| `4`, `80007`, `130429`, `131048` (spam rate limit), `131056` (sender→recipient pair rate limit) | `RateLimited` |
| `131021` (sender is the recipient), `131026` (undeliverable to this recipient) | `InvalidRecipient` |
| `100`, `131008` (missing parameter), `131009`, `131042`, `131045`, `131047` (24-hour window closed — send a template), `131051`, `132000`, `132001`, `132012`, `133010` (the business sender number is not registered) | `InvalidRequest` |
| `1`, `2`, `131000`, `131016`, `131057` | `ProviderUnavailable` |

| HTTP (code not listed) | `ProviderCode` |
|---|---|
| 401 / 403 | `AuthFailed` |
| 429 | `RateLimited` |
| 400 | `InvalidRequest` |
| 5xx | `ProviderUnavailable` |
| other | `Unknown` |

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
