# thinwrap/notifications

Unified PHP facade over 35 notification providers across Email (10), SMS (10), Push (6), and Chat (9). Stateless. Zero vendor SDKs. Bring your own PSR-18 HTTP client.

## Install

```bash
composer require thinwrap/notifications
```

Requires PHP ≥8.2. PSR-18 HTTP client + PSR-17 factories are auto-discovered via
`php-http/discovery` — if you don't already have one installed:

```bash
composer require guzzlehttp/guzzle guzzlehttp/psr7
```

## End-to-end example — 2-minute send

```php
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridConfig;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\Exception\ConnectorError;

$email = new Email(
    NotificationProviderId::Sendgrid,
    new SendgridConfig(apiKey: getenv('SG_KEY')),
);

try {
    $result = $email->send(new EmailSendInput(
        to:      'recipient@example.com',
        from:    'sender@example.com',
        subject: 'Hello from Thinwrap',
        text:    'A short plain-text body.',
    ));
    echo $result->success;            // bool
    echo $result->providerMessageId;  // vendor message id, if returned
} catch (ConnectorError $e) {
    error_log($e->providerCode->value . ': ' . ($e->providerMessage ?? ''));
}
```

## Switching providers

Change the `NotificationProviderId` case and config class; the `EmailSendInput` /
`SmsSendInput` / `PushSendInput` / `ChatSendInput` shape stays identical.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Providers\Twilio\TwilioConfig;
use Thinwrap\Notifications\Providers\Vonage\VonageConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$twilio = new Sms(NotificationProviderId::Twilio, new TwilioConfig(
    accountSid: getenv('TWILIO_SID'),
    authToken:  getenv('TWILIO_TOKEN'),
));
$vonage = new Sms(NotificationProviderId::Vonage, new VonageConfig(
    apiKey:    getenv('VONAGE_KEY'),
    apiSecret: getenv('VONAGE_SECRET'),
));

$sameInput = new SmsSendInput(to: '+14155550100', from: '+14155550199', body: 'Hello');
$twilio->send($sameInput);
$vonage->send($sameInput);
```

## Bring your own PSR-18 client

Inject any PSR-18 client through the `client` parameter on the `*Config` DTO — useful
for tracing, mocking, or proxying through `symfony/http-client`.

**Contract: a non-2xx must be RETURNED, not thrown.** PSR-18 requires this and compliant
clients honour it, so each connector can map the status to a `ProviderCode`
(429 → `RateLimited`, 401 → `AuthFailed`, …), read the vendor's message, and honour
`Retry-After`. A client that raises instead — Guzzle used outside its PSR-18 adapter, or a
decorator calling raise-on-error — is handled defensively: the answered response is
recovered from the exception's `getResponse()` so classification still runs.

```bash
composer require guzzlehttp/guzzle
```

```php
use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$tracingClient = new class(new Client()) implements ClientInterface {
    public function __construct(private Client $inner) {}
    public function sendRequest(RequestInterface $req): ResponseInterface
    {
        error_log('→ ' . $req->getMethod() . ' ' . (string) $req->getUri());
        return $this->inner->sendRequest($req);
    }
};

$email = new Email(
    NotificationProviderId::Sendgrid,
    new SendgridConfig(apiKey: getenv('SG_KEY'), from: 'noreply@example.com'),
    $tracingClient,                // ?ClientInterface — third constructor arg
);
```

`php-http/discovery` auto-detects an installed PSR-18 client (Guzzle, Symfony HttpClient,
Buzz, etc.) when no `$client` is passed to the facade constructor.

The wrapper holds no state — no token cache, no connection pool, no retry buffer. The
**optional** `tokenCache` hook on `FcmConfig` and `ApnsConfig` (the only two connectors
with short-lived signed tokens) lets the consumer amortize signing cost; the hook owns
the state, not the wrapper. See
[`src/Providers/Fcm/README.md`](src/Providers/Fcm/README.md) and
[`src/Providers/Apns/README.md`](src/Providers/Apns/README.md) for hook shape.

## Error handling

Every failure surfaces as `ConnectorError` with a typed `ProviderCode`. Compose your own
retry strategy from `$e->providerCode` and `$e->cause` (which carries the raw
`Retry-After` header where the vendor sets one). The wrapper performs no automatic retry.

```php
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Enum\ProviderCode;

try {
    $email->send($input);
} catch (ConnectorError $e) {
    match ($e->providerCode) {
        ProviderCode::RateLimited         => /* respect Retry-After in $e->cause['retryAfter'] */ null,
        ProviderCode::AuthFailed          => /* rotate credentials                              */ null,
        ProviderCode::InvalidRequest      => /* fix payload                                     */ null,
        ProviderCode::InvalidRecipient    => /* bad destination address                         */ null,
        ProviderCode::ProviderUnavailable => /* transient 5xx — your retry strategy             */ null,
        ProviderCode::Unknown             => /* fallback                                        */ null,
    };
}
```

The 6 `ProviderCode` cases are byte-exact across the TypeScript and PHP packages.
`ConnectorError extends \RuntimeException` — `catch (\Throwable $e)` works too.

### `cause` shape

`$e->cause` is a uniform array across every connector:

```php
[
    'raw'               => mixed,            // the parsed vendor error body (or transport-failure detail), null when absent
    'retryAfter'        => string|int|null,  // the RAW Retry-After value (header string, or body int for Telegram/Discord) — never normalized
    'retryAfterSeconds' => int|null,         // the PARSED Retry-After in seconds (null when no Retry-After present)
]
```

There is **no** top-level structured `retryAfterSeconds` field on `ConnectorError` — the
wrapper performs no retry. The parsed seconds live inside `$e->cause['retryAfterSeconds']`
and are also echoed in `$e->providerMessage` (`… (Retry-After: N seconds)`). `retryAfter`
carries the raw value verbatim (`string|int|null`) and is intentionally not normalized.
Discord additionally exposes its body-sourced `retryAfterBody` (float) in `cause`.

Transport-layer failures (the PSR-18 client throwing before any HTTP response) are
surfaced with a generic `providerMessage` (`Upstream transport error`) and only the
exception **class name** in `cause` — never the raw client-exception message. That
message can embed the full request URL, which for some providers (Telegram bot token in
the path; Slack/Discord/Google Chat/Mattermost/MS Teams webhook URLs) *is* the credential.
Do not log the raw HTTP-client exception directly for the same reason.

## `_passthrough` escape valve

When the normalized input doesn't expose a vendor-specific field, forward arbitrary keys
via the `_passthrough` parameter on the send input. Body merges **deep**, headers and
query merge **shallow**, consumer values win on conflict. Keys are forwarded verbatim.

```php
$email->send(new EmailSendInput(
    to:      'recipient@example.com',
    from:    'sender@example.com',
    subject: 'Hi',
    text:    'Hello',
    _passthrough: [
        'body' => [
            // SendGrid-specific — forwarded into v3/mail/send body verbatim
            'dynamic_template_data' => ['firstName' => 'Alice'],
            'mail_settings'         => ['sandbox_mode' => ['enable' => true]],
        ],
    ],
));
```

Each per-connector README documents vendor-specific `_passthrough` examples.

## Bring your own connector

When `_passthrough` isn't enough — the provider isn't shipped at all — implement the
channel's `Contract\*ConnectorInterface` (a single `send()` method over the normalized
DTOs) and build the facade with `fromConnector()`. You keep the normalized input/result
shapes and the uniform `ConnectorError` path; only the wire call is yours.

```php
use Thinwrap\Notifications\Push;
use Thinwrap\Notifications\Contract\PushConnectorInterface;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushSendResult;
use Thinwrap\Notifications\DTO\Push\PushStatus;

final class NtfyPushConnector implements PushConnectorInterface
{
    public function __construct(private \Psr\Http\Client\ClientInterface $client) {}

    public function send(PushSendInput $input): PushSendResult
    {
        // your wire call — e.g. POST https://ntfy.sh/{$input->to}
        $response = $this->client->sendRequest(/* ... */);

        return new PushSendResult(
            success:           true,
            status:            PushStatus::Sent,
            providerMessageId: null,
            raw:               (string) $response->getBody(),
        );
    }
}

$push = Push::fromConnector(new NtfyPushConnector($client));
$push->send(new PushSendInput(to: 'deploys', title: 'Deploy', body: 'v1.0 is live'));
```

Throw `ConnectorError` from `send()` for hard failures so consumers keep a single
error-handling path; return `success: false` for HTTP-2xx-but-rejected soft-rejects,
matching the built-in connectors.

## Language constraints (PHP)

- PHP 8.2 minimum; CI matrix runs on **8.2, 8.3, and 8.4** (Linux only at v1.0).
- PSR-18 HTTP client is BYO — `php-http/discovery` auto-detects Guzzle, Symfony
  HttpClient, Buzz, etc., when no `$client` is passed to the facade constructor.
- No vendor SDKs. The only runtime dependency is `php-http/discovery` (which
  auto-wires a PSR-18 client when none is injected); `psr/http-client` +
  `psr/http-factory` + `psr/http-message` are interface-only packages.
- 35 providers across 4 channels — same normalized facade surface as the TypeScript
  sibling `@thinwrap/notifications`. The PHP package exposes only the modern
  `send()` surface (no legacy brownfield provider interfaces).

## Per-connector documentation

Each per-connector README documents auth, endpoints (regional / sandbox), narrowed input
augmentations, error-code mappings, and `_passthrough` examples.

### Email (10)

| Provider | README |
|---|---|
| `ses`        | [src/Providers/Ses/README.md](src/Providers/Ses/README.md) |
| `resend`     | [src/Providers/Resend/README.md](src/Providers/Resend/README.md) |
| `mailgun`    | [src/Providers/Mailgun/README.md](src/Providers/Mailgun/README.md) |
| `sendgrid`   | [src/Providers/Sendgrid/README.md](src/Providers/Sendgrid/README.md) |
| `postmark`   | [src/Providers/Postmark/README.md](src/Providers/Postmark/README.md) |
| `mailersend` | [src/Providers/Mailersend/README.md](src/Providers/Mailersend/README.md) |
| `mailtrap`   | [src/Providers/Mailtrap/README.md](src/Providers/Mailtrap/README.md) |
| `brevo`      | [src/Providers/Brevo/README.md](src/Providers/Brevo/README.md) |
| `sparkpost`  | [src/Providers/Sparkpost/README.md](src/Providers/Sparkpost/README.md) |
| `scaleway`   | [src/Providers/Scaleway/README.md](src/Providers/Scaleway/README.md) |

### SMS (10)

| Provider | README |
|---|---|
| `vonage`      | [src/Providers/Vonage/README.md](src/Providers/Vonage/README.md) |
| `twilio`      | [src/Providers/Twilio/README.md](src/Providers/Twilio/README.md) |
| `plivo`       | [src/Providers/Plivo/README.md](src/Providers/Plivo/README.md) |
| `sns`         | [src/Providers/Sns/README.md](src/Providers/Sns/README.md) |
| `sinch`       | [src/Providers/Sinch/README.md](src/Providers/Sinch/README.md) |
| `telnyx`      | [src/Providers/Telnyx/README.md](src/Providers/Telnyx/README.md) |
| `infobip`     | [src/Providers/Infobip/README.md](src/Providers/Infobip/README.md) |
| `messagebird` | [src/Providers/Messagebird/README.md](src/Providers/Messagebird/README.md) |
| `textmagic`   | [src/Providers/Textmagic/README.md](src/Providers/Textmagic/README.md) |
| `d7networks`  | [src/Providers/D7networks/README.md](src/Providers/D7networks/README.md) |

### Push (6)

| Provider | README |
|---|---|
| `fcm`          | [src/Providers/Fcm/README.md](src/Providers/Fcm/README.md) |
| `expo`         | [src/Providers/Expo/README.md](src/Providers/Expo/README.md) |
| `apns`         | [src/Providers/Apns/README.md](src/Providers/Apns/README.md) |
| `one-signal`   | [src/Providers/OneSignal/README.md](src/Providers/OneSignal/README.md) |
| `pusher-beams` | [src/Providers/PusherBeams/README.md](src/Providers/PusherBeams/README.md) |
| `wonderpush`   | [src/Providers/Wonderpush/README.md](src/Providers/Wonderpush/README.md) |

### Chat (9)

| Provider | README |
|---|---|
| `telegram`          | [src/Providers/Telegram/README.md](src/Providers/Telegram/README.md) |
| `slack`             | [src/Providers/Slack/README.md](src/Providers/Slack/README.md) |
| `whatsapp-business` | [src/Providers/WhatsappBusiness/README.md](src/Providers/WhatsappBusiness/README.md) |
| `discord`           | [src/Providers/Discord/README.md](src/Providers/Discord/README.md) |
| `msteams`           | [src/Providers/Msteams/README.md](src/Providers/Msteams/README.md) |
| `google-chat`       | [src/Providers/GoogleChat/README.md](src/Providers/GoogleChat/README.md) |
| `mattermost`        | [src/Providers/Mattermost/README.md](src/Providers/Mattermost/README.md) |
| `rocket-chat`       | [src/Providers/RocketChat/README.md](src/Providers/RocketChat/README.md) |
| `line`              | [src/Providers/Line/README.md](src/Providers/Line/README.md) |

## Baseline-coverage discipline

The unified facade surface includes only features ≥90% of providers in each channel
support natively. Sub-baseline fields are accessible via per-provider narrowed input
DTOs (`<Provider>NarrowedInput`) and the `_passthrough` escape hatch.

## Migrating

### From a vendor PHP SDK

```php
// Before — twilio/sdk
$twilio = new \Twilio\Rest\Client($sid, $token);
$twilio->messages->create('+14155550100', ['from' => '+14155550199', 'body' => 'Hi']);

// After
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Providers\Twilio\TwilioConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$sms = new Sms(NotificationProviderId::Twilio, new TwilioConfig(accountSid: $sid, authToken: $token));
$sms->send(new SmsSendInput(to: '+14155550100', from: '+14155550199', body: 'Hi'));
```

```php
// Before — sendgrid/sendgrid-php
$sg = new \SendGrid(getenv('SG_KEY'));
$mail = new \SendGrid\Mail\Mail();
$mail->setFrom('from@example.com');
$mail->addTo('to@example.com');
$mail->setSubject('Hi');
$mail->addContent('text/plain', 'Hello');
$sg->send($mail);

// After
$email = new Email(NotificationProviderId::Sendgrid, new SendgridConfig(apiKey: getenv('SG_KEY')));
$email->send(new EmailSendInput(to: 'to@example.com', from: 'from@example.com', subject: 'Hi', text: 'Hello'));
```

Vendor-SDK conveniences (auto-retry, telemetry, idempotency-key generation) are
intentionally absent — compose your own.

### From hand-rolled HTTP / Guzzle

If you've been hand-rolling vendor HTTP calls with Guzzle, the facade collapses the
boilerplate to one line per call. Error handling and retry composition stay yours; the
PSR-18 client passes through unchanged via the facade constructor's `$client` argument.

### From a previous Thinwrap PHP version

Not applicable at v1.0; `thinwrap/notifications` has not previously published. Forward
looking: when v2.0 ships with breaking changes, this section will carry the v1→v2
recipe.

## For AI agents and contributors

- [`.ai/guidelines.md`](.ai/guidelines.md) — contributor entry point: how to add a connector.
- [`.ai/ARCHITECTURE.md`](.ai/ARCHITECTURE.md) — facade-dispatch-base pattern + invariants.
- [`.ai/CONVENTIONS.md`](.ai/CONVENTIONS.md) — naming, file layout, test patterns.

AI agents working with this package should consult `.ai/guidelines.md` first.

## Security

Report vulnerabilities **privately** — please do not open a public issue. Preferred: a
[private security advisory](https://github.com/thinwrap/notifications-php/security/advisories/new)
on this repository. Alternatively, email **security@thinwrap.dev**. Include the affected
versions and a minimal reproduction if you have one.

A vulnerability in a *provider's* own API or service belongs to that vendor rather than
to this wrapper — please report those upstream.

Supply chain: releases are cosign-signed via GitHub Actions OIDC (no static signing
keys), maintainer accounts require two-factor authentication on GitHub, and Packagist
consumes the package via webhook auto-sync — no long-lived Packagist API token is stored
anywhere.

## License

MIT — see [`LICENSE`](LICENSE).

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md).
