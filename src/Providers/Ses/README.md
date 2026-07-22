# Amazon SES Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Ses` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Ses\SesConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new SesConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Ses, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`SesConfig(region: string, accessKeyId: string, secretAccessKey: string, sessionToken?: ?string, from?: ?string, senderName?: ?string)`

`from` is the default sender used when `EmailSendInput.from` is empty; `senderName` (when set) wraps the resolved address as `Name <addr>`. `sessionToken` carries STS temporary credentials.

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

IAM access key + secret with `ses:SendEmail` permission. SigV4 derived per request — no token caching.

## Endpoint

`https://email.<region>.amazonaws.com/v2/email/outbound-emails` — region-derived, no global endpoint. Available regions: `us-east-1`, `us-west-2`, `eu-west-1`, `eu-central-1`, `ap-south-1`, `ap-southeast-1`, `ap-northeast-1`.

## Narrowed input augmentations

Standard email input applies. Attachments and custom `headers` are fully supported: when either is present the connector builds a raw multipart MIME message and sends it via SES `Content.Raw` (header values CRLF-stripped, non-ASCII From/Subject RFC 2047 encoded); otherwise it uses the simpler `Content.Simple` structure. Attachment `content` accepts a string or a PHP stream resource uniformly. `EmailSendInput.tags` map to SES `EmailTags: [{Name, Value}]`. SES v2-specific fields (e.g. `ConfigurationSetName`, `Tags`, template ARNs) go through `_passthrough.body` using PascalCase wire keys.

## Error mapping

SES classifies the body error code (`__type` / `type` / `Code`, split on `#`) **first**; it takes precedence over the HTTP-status defaults below.

| Vendor HTTP | Vendor signal (`__type` / `Code`) | `ProviderCode` |
|---|---|---|
| (any) | `MessageRejected`, `InvalidEmailAddress` | `InvalidRecipient` |
| (any) | `ValidationException`, `InvalidParameter`, `InvalidParameterValue`, `BadRequestException`, `MailFromDomainNotVerified`, `FromEmailAddressNotVerified` | `InvalidRequest` |
| (any) | `AccessDenied`, `NotAuthorized`, `SignatureDoesNotMatch`, `InvalidClientTokenId`, `AccountSendingPaused`, `AccountSuspended` | `AuthFailed` |
| (any) | `Throttling`, `ThrottlingException`, `TooManyRequestsException` | `RateLimited` |
| (any) | `ServiceUnavailable`, `InternalFailure` | `ProviderUnavailable` |
| 401 / 403 | (no code match) | `AuthFailed` |
| 429 | (no code match) | `RateLimited` |
| 5xx | (no code match) | `ProviderUnavailable` |
| 4xx | (no code match) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

`*Exception`-suffixed aliases (e.g. `ThrottlingException`, `AccountSuspendedException`) map identically to their base codes. A request body that fails JSON encoding surfaces as `InvalidRequest` locally before any network call.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward AWS-specific fields (e.g. `ConfigurationSetName`, `Tags`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html>
- Regional endpoints: <https://docs.aws.amazon.com/general/latest/gr/ses.html>
- AWS SigV4: <https://docs.aws.amazon.com/general/latest/gr/signature-version-4.html>
- Sending quotas: <https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas.html>
