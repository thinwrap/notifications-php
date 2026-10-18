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

Create the webhook with Workflows: in the Teams channel, add a workflow from the **"When a Teams webhook request is received"** trigger (Power Automate / Logic Apps) and copy its HTTP POST URL. Microsoft 365 Connectors ("Connectors" → "Incoming Webhook") are nearing deprecation; existing connector URLs are still accepted. Either way the URL itself is the credential (Workflows URLs carry a `sig` query parameter).

## Endpoint

The configured `webhookUrl`, as-is:

- Workflows: `https://{…}.logic.azure.com/workflows/{id}/triggers/manual/paths/invoke?…&sig={token}` or a `https://{…}.environment.api.powerplatform.com/powerautomate/automations/direct/workflows/{id}/…` URL
- Legacy Microsoft 365 Connector: `https://{tenant}.webhook.office.com/webhookb2/{id}/IncomingWebhook/{token}`

## Narrowed input augmentations

`to` is **ignored** (the webhook is pinned to its channel) and is intentionally `null` on the base input. `body` is synthesized into an Adaptive Card v1.4 with a single `TextBlock`, wrapped in the required Teams envelope: `{type: "message", attachments: [{contentType: "application/vnd.microsoft.card.adaptive", content: <card>}]}`. On success `status` is `Sent`. A Workflows webhook answers `202 Accepted` with an empty body; a legacy connector answers `200` with the plain text `1`. Neither returns a message id, so `providerMessageId` is **always `null`** and `raw` carries the response text.

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

Teams quirk: revoked / malformed webhook URLs return HTTP 400 with a plain-text body containing `Invalid webhook URL` (not 404), special-cased to `AuthFailed`. Success detection: `202` is always success (a Workflows trigger accepts the run with no body). Any other 2xx whose body embeds `HTTP error NNN` (legacy connectors report failures, throttling included, as **HTTP 200 with an error string**, e.g. `...Teams endpoint returned HTTP error 429 with ContextId...`) is remapped to that status and classified through the same table. From a legacy connector host (`*.webhook.office.com`, `outlook.office.com`) only the literal `1` means success, so any other 2xx body is remapped to 502; from a Workflows host any other 2xx is success. Before dispatch, an empty `webhookUrl` or a non-`https://` `webhookUrl` raises `InvalidRequest` (400) locally.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Adaptive Card payloads (`type: "AdaptiveCard"`, `attachments`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://learn.microsoft.com/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook>
- Workflows Request trigger (202 response): <https://learn.microsoft.com/azure/connectors/connectors-native-reqres>
- Adaptive Cards: <https://adaptivecards.io/explorer/>
