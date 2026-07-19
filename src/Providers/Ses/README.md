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

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Attachments & tags

Attachments and custom `headers` are fully supported: the connector builds a raw multipart MIME message and sends it via SES `Content.Raw` (header values CRLF-stripped, non-ASCII From/Subject RFC 2047 encoded). `EmailSendInput.tags` map to SES `EmailTags: [{Name, Value}]`. Attachment `content` accepts a string or a PHP stream resource uniformly.

## Auth setup

IAM access key + secret with `ses:SendEmail` permission. SigV4 derived per request — no token caching.

## Endpoint

`https://email.us-east-1.amazonaws.com/`

## Regional endpoints

`us-east-1`, `us-west-2`, `eu-west-1`, `eu-central-1`, `ap-south-1`, `ap-southeast-1`, `ap-northeast-1`

## Rate limits

See vendor docs: <https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas.html>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 2** (auth-heavy: JWT signing or AWS SigV4).

## `_passthrough` example

Forward AWS-specific fields (e.g. `ConfigurationSetName`, `Tags`) via `_passthrough.body`.
