# SparkPost Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Sparkpost` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Sparkpost\SparkpostConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new SparkpostConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Sparkpost, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`SparkpostConfig(apiKey: string, region: ?SparkpostRegion = null)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

API key from SparkPost dashboard. Sent as `Authorization: <key>` header (no `Bearer` prefix — vendor idiom). EU endpoint at `api.eu.sparkpost.com`.

## Endpoint

`https://api.sparkpost.com/api/v1/transmissions`

## Regional endpoints

`us`, `eu`

## Rate limits

See vendor docs: <https://www.sparkpost.com/docs/tech-resources/api-rate-limiting/>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward SparkPost-specific fields. CC/BCC are translated to `recipients[].header_to` + `content.headers.CC` at the wire layer (outlier).
