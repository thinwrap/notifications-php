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

`from` is the default sender used when `EmailSendInput.from` is empty; `senderName` (when set) becomes the display name of the resolved address. The name is quoted when it holds RFC 5322 specials (`"Acme, Inc." <addr>`) and sent as RFC 2047 encoded-words when it is non-ASCII, as SES requires for a Unicode "friendly from" name; a `from` that already reads `Name <addr>` keeps its own name. `sessionToken` carries STS temporary credentials.

`region` must be an AWS region name (checked before sending, since it becomes the host the signed request goes to).

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

IAM access key + secret with `ses:SendEmail` permission. SigV4 derived per request — no token caching.

## Endpoint

`https://email.<region>.amazonaws.com/v2/email/outbound-emails` — region-derived, no global endpoint. Available regions: `us-east-1`, `us-west-2`, `eu-west-1`, `eu-central-1`, `ap-south-1`, `ap-southeast-1`, `ap-northeast-1`.

## Narrowed input augmentations

Standard email input applies. Every message is sent as SES v2 `Content.Simple`:

- **Attachments** map to `Content.Simple.Attachments` (`FileName`, base64 `RawContent`, `ContentType` when set). An attachment with a `contentId` is sent with `ContentId` + `ContentDisposition: INLINE`, so `<img src="cid:…">` resolves; the rest are `ATTACHMENT`. Attachment `content` accepts a string or a PHP stream resource uniformly.
- **Custom `headers`** map to `Content.Simple.Headers` and follow SES's rules, checked before sending (`InvalidRequest`): at most 15 headers; names printable ASCII without `:` (max 126); values printable ASCII (max 995; name + value max 996) — a non-ASCII value is sent as RFC 2047 encoded-words (at most 75 characters each), and the limits apply to the encoded form. SES sets `BCC`, `CC`, `Content-Disposition`, `Content-Type`, `Date`, `From`, `Message-ID`, `MIME-Version`, `Reply-To`, `Return-Path`, `Subject` and `To` itself and rejects them as custom headers — use `cc` / `bcc` / `replyTo` / `subject` instead.
- **`tags`** map to SES `EmailTags: [{Name: tag, Value: tag}]`. SES allows only ASCII letters, digits, `_` and `-` (max 256) in a tag, so any other tag is `InvalidRequest` before sending.

SES v2-specific fields (e.g. `ConfigurationSetName`, `EmailTags`, `FeedbackForwardingEmailAddress`, template ARNs) go through `_passthrough.body` using PascalCase wire keys.

## Error mapping

SES classifies the error code **first**; it takes precedence over the HTTP-status defaults below. Most SendEmail errors share HTTP 400, so the code is read the way the AWS SDKs read it: the `x-amzn-ErrorType` header, else body `code` / `Code`, else `__type`, cut at `:` and after the last `#`.

| Vendor HTTP | Vendor signal (`__type` / `Code`) | `ProviderCode` |
|---|---|---|
| (any) | `InvalidEmailAddress` | `InvalidRecipient` |
| (any) | `MessageRejected` ("contains invalid content"), `ValidationException`, `InvalidParameter`, `InvalidParameterValue`, `BadRequestException`, `MailFromDomainNotVerified`, `FromEmailAddressNotVerified` | `InvalidRequest` |
| (any) | `AccessDenied`, `NotAuthorized`, `SignatureDoesNotMatch`, `InvalidClientTokenId`, `SendingPausedException`, `AccountSendingPaused`, `AccountSuspended` | `AuthFailed` |
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

Forward AWS-specific fields (e.g. `ConfigurationSetName`, `EmailTags`, `FeedbackForwardingEmailAddress` — the v2 name for v1's `ReturnPath`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html>
- Regional endpoints: <https://docs.aws.amazon.com/general/latest/gr/ses.html>
- AWS SigV4: <https://docs.aws.amazon.com/general/latest/gr/signature-version-4.html>
- Sending quotas: <https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas.html>
