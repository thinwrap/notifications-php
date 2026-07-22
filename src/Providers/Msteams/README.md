# Microsoft Teams Chat Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Msteams` is passed to the `Chat` facade.

```php
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Msteams\MsteamsConfig;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

$cfg = new MsteamsConfig(/* see config shape below */);
$chat = new Chat(NotificationProviderId::Msteams, $cfg);
$result = $chat->send(new ChatSendInput(to: null, body: 'Hello, world!'));
```

## Configuration

`MsteamsConfig(webhookUrl: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Incoming-webhook URL from a Teams channel → "Connectors" → "Incoming Webhook". URL itself is the credential.

## Endpoint

`https://{tenant}.webhook.office.com/webhookb2/{id}/IncomingWebhook/{token}`

## Narrowed input augmentations

`to` is **ignored** (the webhook is pinned to its channel) and is intentionally `null` on the base input. `body` is synthesized into an Adaptive Card v1.4 with a single `TextBlock`, wrapped in the required Teams envelope: `{type: "message", attachments: [{contentType: "application/vnd.microsoft.card.adaptive", content: <card>}]}`. On success `status` is `Sent`. Teams Incoming Webhooks return the plain text `1` (no JSON, no message id), so `providerMessageId` is **always `null`** and `raw` carries the text.

`MsteamsChatSendInput` extends `ChatSendInput` with a single below-baseline field, `card` (a full Adaptive Card payload as `array<string,mixed>`); when set it replaces the synthesized body-as-text card.

Any further Teams-specific fields go via `_passthrough['body']`, which is forwarded verbatim (no casing transform). To send a full custom Adaptive Card (or a legacy MessageCard), supply `attachments` via `_passthrough['body']` — the array replaces the synthesized value on merge.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 400 | body matches `invalid webhook` | `AuthFailed` |
| 401 / 403 / 404 | (webhook removed) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 400 | (other — malformed card) | `InvalidRequest` |
| 5xx | (any) | `ProviderUnavailable` |
| — | (unmatched) | `Unknown` |

Teams quirk: revoked / malformed webhook URLs return HTTP 400 with a plain-text body containing `Invalid webhook URL` (not 404), special-cased to `AuthFailed`. A further PHP-specific stricture (stricter than the TS/Go siblings): Teams often returns **HTTP 200 with an error string in the body** (e.g. `...Teams endpoint returned HTTP error 429 with ContextId...`) — only the literal `1` means success, so any other 2xx body is remapped to an effective status (the embedded `HTTP error NNN` code when present, else 502) and classified through the same table. Before dispatch, an empty `webhookUrl` or a non-`https://` `webhookUrl` raises `InvalidRequest` (400) locally.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Adaptive Card payloads (`type: "AdaptiveCard"`, `attachments`) via `_passthrough.body`. PHP connector validates that the response body is exactly `"1"` and throws on any other shape (stricter than TS sibling).

## Vendor docs

- API reference: <https://learn.microsoft.com/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook>
- Adaptive Cards: <https://adaptivecards.io/explorer/>
