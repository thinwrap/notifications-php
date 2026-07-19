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

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

IAM access key + secret with `sns:Publish` permission. SigV4 derived per request — no token caching.

## Endpoint

`https://sns.us-east-1.amazonaws.com/`

## Regional endpoints

`us-east-1`, `us-west-2`, `eu-west-1`, `eu-central-1`, `ap-south-1`, `ap-southeast-1`, `ap-northeast-1`

## Rate limits

See vendor docs: <https://docs.aws.amazon.com/sns/latest/dg/sms_limits.html>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 2** (auth-heavy: JWT signing or AWS SigV4).

## `_passthrough` example

Forward AWS-specific fields (e.g. `MessageAttributes`, `MessageDeduplicationId`) via `_passthrough.body`.
