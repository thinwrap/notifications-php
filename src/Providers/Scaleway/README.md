---
providerId: scaleway
channel: email
auth:
  method: header
  tokenLifecycle: opaque-static
  tokenCacheHookSupported: false
endpoint:
  default: https://api.scaleway.com/transactional-email/v1alpha1/regions/fr-par/emails
  regional:
    - fr-par
    - nl-ams
    - pl-waw
versioning:
  vendorApiVersion: "v1alpha1"
  lastVerified: 2026-06-02
tier: 2
rateLimitDocsUrl: https://www.scaleway.com/en/docs/transactional-email/
retryAfterSurfaced: true
notes_passthrough: |
  Scaleway TEM uses snake_case wire keys. Forward vendor-specific fields (e.g.
  `scheduled_at`) via `_passthrough['body']` — PHP consumers pre-shape
  snake_case keys at the call site.
---

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

## Auth setup

Generate an API key (Access key + Secret key) under Scaleway IAM. Only the
**secret key** is used here, sent verbatim in the `X-Auth-Token` header. Static.

## Endpoint

`POST https://api.scaleway.com/transactional-email/v1alpha1/regions/{region}/emails`

The region is part of the URL path (not a host swap).

## Wire mapping

| Thinwrap input | Scaleway wire field |
|---|---|
| `from` / `config->from` (+ `senderName`) | `from: { email, name? }` |
| `to` | `to: [{ email }]` |
| `cc` / `bcc` | `cc` / `bcc: [{ email }]` |
| `subject` / `text` / `html` | `subject` / `text` / `html` |
| `config->projectId` | `project_id` |
| `headers` | `additional_headers: [{ key, value }]` |
| `replyTo` | folded into `additional_headers` as `Reply-To` |
| `attachments` | `attachments: [{ name, type, content(base64) }]` |

### Graceful degradation

Per baseline-coverage discipline, fields with no Scaleway equivalent at v1.0 are
silently dropped (no error, no warning):

- **`tags`** — Scaleway TEM has no tags field; dropped.
- **attachment `contentId`** — no inline-image field; dropped (still sent as a
  normal attachment).

Attachments without a `contentType` default to `application/octet-stream`
(Scaleway requires a `type` on every attachment).

## Error mapping

| Vendor HTTP | `ProviderCode` |
|---|---|
| 401 / 403 | `AuthFailed` |
| 400 / 404 / 422 | `InvalidRequest` |
| 429 | `RateLimited` |
| 5xx | `ProviderUnavailable` |
| transport failure | `ProviderUnavailable` |

`Retry-After` is surfaced in `ConnectorError->providerMessage` and on
`cause['retryAfter']` (raw) / `cause['retryAfterSeconds']` (parsed). There is no
top-level `retryAfterSeconds` field — retry is consumer policy.

## Success mapping

A 2xx response (`{ emails: [{ id, message_id, status: "new" }] }`) maps to
`EmailSendResult(success: true, status: EmailStatus::Queued, providerMessageId: emails[0].message_id)`
— Scaleway accepts for delivery asynchronously, so `Queued` is vendor-faithful.

## Vendor docs

- API reference: https://www.scaleway.com/en/developers/api/transactional-email/
- Send an email: https://www.scaleway.com/en/docs/transactional-email/api-cli/send-emails-with-api/
- IAM API keys: https://www.scaleway.com/en/docs/transactional-email/how-to/generate-api-keys-for-tem-with-iam/
