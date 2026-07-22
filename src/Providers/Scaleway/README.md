# Scaleway Transactional Email (TEM) Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Scaleway` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Scaleway\ScalewayConfig;
use Thinwrap\Notifications\Providers\Scaleway\ScalewayRegion;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new ScalewayConfig(
    secretKey: getenv('SCW_SECRET_KEY'),   // sent in X-Auth-Token header
    projectId: getenv('SCW_PROJECT_ID'),   // required — wire field project_id
    from: 'noreply@example.com',
    senderName: 'Acme',                    // optional → from.name
    region: ScalewayRegion::FrPar,         // optional — default fr-par
);
$email = new Email(NotificationProviderId::Scaleway, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

| Field | Type | Required | Notes |
|---|---|---|---|
| `secretKey` | `string` | yes | Scaleway IAM API secret key, sent in the `X-Auth-Token` header |
| `projectId` | `string` | yes | Written as `project_id` on every send |
| `from` | `string` | yes | Default sender email |
| `senderName` | `?string` | no | Composed into `from.name` |
| `region` | `?ScalewayRegion` | no | Default `FrPar`; interpolated into the endpoint **path** |

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Generate an API key (Access key + Secret key) under Scaleway IAM. Only the
**secret key** is used here, sent verbatim in the `X-Auth-Token` header. Static.

## Endpoint

`POST https://api.scaleway.com/transactional-email/v1alpha1/regions/{region}/emails`

The region is part of the URL path (not a host swap); it defaults to `fr-par` (valid `fr-par`, `nl-ams`, `pl-waw`).

## Narrowed input augmentations

The wire body is snake_case. `from`/`config->from` (+ `config->senderName`) → `from` (`{email, name?}`); `to` → `to` (`[{email}]`); `subject` → `subject`; `config->projectId` → `project_id`; `cc`/`bcc` → `cc`/`bcc` (`[{email}]`); `text`/`html` → `text`/`html`; `headers` → `additional_headers` (`[{key, value}]`); `replyTo` is folded into `additional_headers` as a `Reply-To` entry (no first-class field); `attachments` → `attachments` (`{name, type, content` (base64)`}`, with an empty `contentType` defaulting to `application/octet-stream` — Scaleway requires a `type` on every attachment). On success `status` is `Queued` (Scaleway accepts for delivery asynchronously, returning `{emails: [{id, message_id, status: "new"}]}`) and `providerMessageId` comes from `emails[0].message_id`, falling back to `emails[0].id`.

**Graceful degradation** (per the ≥90% baseline rule, silently dropped — no error, no warning): `tags` has no Scaleway field at v1.0; attachment `contentId` has no inline-image field (the file is still sent as a normal attachment).

`ScalewayNarrowedInput` extends `EmailSendInput` but adds **no new fields** — Scaleway has no first-class fields beyond the baseline at v1.0; it exists only for symmetry with the other connectors.

Any further Scaleway-specific fields go via `_passthrough['body']`, which merges verbatim. Unlike the TS/Go siblings, the PHP connector does **not** apply a camelCase→snake_case transform on `_passthrough` (no `CasingTransform` utility in the bootstrap) — supply the exact snake_case wire keys directly (e.g. `scheduled_at`).

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| other 4xx (400 / 404 / 422 / …) | (any) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

Scaleway has no body-level error-code disambiguator; HTTP status is the sole signal. The vendor `message` (or `errors[0].message`) is read defensively for `$e->providerMessage`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward Scaleway-specific fields (e.g. `scheduled_at`) via `_passthrough.body`. Supply snake_case wire keys directly — the PHP connector does not transform passthrough casing.

## Vendor docs

- API reference: <https://www.scaleway.com/en/developers/api/transactional-email/>
- Send an email: <https://www.scaleway.com/en/docs/transactional-email/api-cli/send-emails-with-api/>
- IAM API keys: <https://www.scaleway.com/en/docs/transactional-email/how-to/generate-api-keys-for-tem-with-iam/>
