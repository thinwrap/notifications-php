# Amazon SNS Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Sns` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Sns\SnsConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new SnsConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::Sns, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`SnsConfig(accessKeyId: string, secretAccessKey: string, region: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

IAM access key + secret with `sns:Publish` permission. SigV4 derived per request — no token caching.

## Endpoint

`https://sns.{region}.amazonaws.com/` — AWS Query API (`Action=Publish`, `Version=2010-03-31`), `application/x-www-form-urlencoded` request, XML response. SMS-eligible regions include `us-east-1`, `us-west-2`, `eu-west-1`, `eu-central-1`, `ap-south-1`, `ap-southeast-1`, `ap-northeast-1`.

## Narrowed input augmentations

Standard SMS input applies: `to` (phone, E.164) maps to `PhoneNumber` and `body` maps to `Message`. Either `to` or a `topicArn` is required. The response is **XML**; on success `providerMessageId` is the `<MessageId>` tag and `raw` holds the raw XML string (not decoded JSON).

`SnsNarrowedInput` extends `SmsSendInput` with `topicArn`, `messageAttributes`, `messageStructure`, `smsType`, `senderId`, and `maxPrice`. The SMS-convenience fields (`smsType`/`senderId`/`maxPrice`) plus any `messageAttributes` are flattened into indexed AWS Query keys `MessageAttributes.entry.N.{Name,Value.DataType,Value.StringValue,Value.BinaryValue}` (including the `AWS.SNS.SMS.{SMSType,SenderID,MaxPrice}` attributes) **before signing**, so the signed canonical request covers them. Any further AWS Query fields (e.g. `TopicArn`, `MessageStructure`) go via `_passthrough.body` using the AWS keys **verbatim** — the indexed-attribute keys are not simple-case transformable.

## Error mapping

The HTTP status is checked, and the XML `<Code>` disambiguates within 4xx:

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| (any) | `<Code>` `InvalidClientTokenId` / `SignatureDoesNotMatch` | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| (any) | `<Code>` `Throttling` / `ThrottlingException` | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| 4xx | `<Code>` `InvalidParameter` / `InvalidParameterValue` | `InvalidRequest` |
| other 4xx | (no matching `<Code>`) | `InvalidRequest` |
| — | (unmatched) | `Unknown` |

The `<Code>`/`<Message>` are extracted from the XML error body. Before dispatch, a request with no `to` **and** no `topicArn` raises `InvalidRequest` (400) locally.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward AWS-specific fields (e.g. `MessageAttributes`, `MessageDeduplicationId`) via `_passthrough.body`.

## Vendor docs

- API reference: https://docs.aws.amazon.com/sns/latest/api/API_Publish.html
- SMS attributes: https://docs.aws.amazon.com/sns/latest/dg/sms_sending-overview.html#sms_publish-to-phone
- Regional availability: https://docs.aws.amazon.com/sns/latest/dg/sns-supported-regions-countries.html
- SMS limits: https://docs.aws.amazon.com/sns/latest/dg/sms_limits.html
