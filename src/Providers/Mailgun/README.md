# Mailgun Email Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::Mailgun` is passed to the `Email` facade.

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Mailgun\MailgunConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

$cfg = new MailgunConfig(/* see config shape below */);
$email = new Email(NotificationProviderId::Mailgun, $cfg);
$result = $email->send(new EmailSendInput(from: 'sender@example.com', to: 'recipient@example.com', subject: 'Hello', text: 'Hello, world!'));
```

## Configuration

`MailgunConfig(apiKey: string, domain: string, region: ?MailgunRegion = null)`

The `client` field on every config accepts an optional `Psr\Http\Client\ClientInterface` (BYO PSR-18, defaults to `php-http/discovery`).

## Auth setup

API key from Mailgun dashboard. HTTP Basic auth as `api:<key>`. EU endpoint at `api.eu.mailgun.net`.

## Endpoint

`https://api.mailgun.net/v3/{domain}/messages`

## Regional endpoints

`us`, `eu`

## Rate limits

See vendor docs: <https://documentation.mailgun.com/docs/mailgun/api-reference/intro/#rate-limit>. The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage`. There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## Tier

This connector is **Tier 1** (lightweight static or webhook auth).

## `_passthrough` example

Forward Mailgun-specific form fields (e.g. `o:tag`, `o:tracking`, `v:my-var`) via `_passthrough.body`.
