# `thinwrap/notifications` (PHP) — Conventions

Naming, file layout, and test patterns for AI agents adding or refactoring a connector.
The per-connector README frontmatter schema authoritative source is
[`../schemas/connector-readme-schema.yaml`](../schemas/connector-readme-schema.yaml).

## Where files live in this repo

```
src/
  Email.php  Sms.php  Push.php  Chat.php       # top-level channel facades
  Base/
    BaseConnector.php                          # sendGet / sendPostJson / sendPostForm
  Contract/
    EmailConnectorInterface.php                # 4 channel contracts
    SmsConnectorInterface.php
    PushConnectorInterface.php
    ChatConnectorInterface.php
    TokenCacheHook.php                         # optional FCM/APNs cache seam
  DTO/
    Email/EmailSendInput.php  EmailSendResult.php
    Sms/SmsSendInput.php      SmsSendResult.php
    Push/PushSendInput.php    PushSendResult.php
    Chat/ChatSendInput.php    ChatSendResult.php
  Enum/
    NotificationProviderId.php                 # backed string enum, one per provider
    ProviderCode.php                           # 6-case canonical error enum
  Exception/
    ConnectorError.php                         # unified error type
  Util/
    Passthrough.php                            # 2-arg merge helper
  Providers/<Pascal>/
    <Provider>Config.php                       # final readonly class
    <Provider><Channel>Connector.php           # one connector per file
    <Provider>NarrowedInput.php                # optional, per-provider narrowed input
    <Provider>Region.php                       # optional, regional enum (e.g. TwilioRegion)
    README.md                                  # per-connector README + YAML frontmatter
tests/
  Base/  DTO/  Enum/  Exception/  Facade/  Util/
  Providers/<Pascal>/<Provider><Channel>ConnectorTest.php
  Fixtures/RetryAfterFixtures.php              # PSR-7 Retry-After helpers
  Fixtures/TokenCacheFixtures.php              # TokenCacheHook spies
schemas/
  connector-readme-schema.yaml                 # frontmatter schema (CI-enforced)
scripts/
  validate-frontmatter.php                     # pure-PHP validator (no symfony/yaml)
.ai/
  guidelines.md  ARCHITECTURE.md  CONVENTIONS.md  TEST-FIXTURES.md
.github/workflows/
  ci.yml  lint-gates.yml  release.yml
```

## `NotificationProviderId` backed enum

Provider IDs are cases on the backed string enum
`Thinwrap\Notifications\Enum\NotificationProviderId`. String backing values are
lowercase, kebab-case where compound (`'whatsapp-business'`, `'one-signal'`,
`'pusher-beams'`, `'rocket-chat'`, `'google-chat'`). Adding a connector = one new case +
one new dispatch arm in the owning facade.

```php
enum NotificationProviderId: string
{
    // Email (9)
    case Ses = 'ses';  case Resend = 'resend';  case Mailgun = 'mailgun';
    case Sendgrid = 'sendgrid';  case Postmark = 'postmark';
    // SMS (10) — no `nexmo` alias; `Vonage` is the single canonical id for Vonage/Nexmo
    case Vonage = 'vonage';  case Twilio = 'twilio'; …
    // Push (6)
    case Fcm = 'fcm';  case Apns = 'apns'; …
    // Chat (9)
    case Slack = 'slack';  case WhatsappBusiness = 'whatsapp-business'; …
}
```

## File naming

| File | Required? | Purpose |
|---|---|---|
| `src/Providers/<Pascal>/<Provider><Channel>Connector.php` | yes | Connector class extending `BaseConnector` |
| `src/Providers/<Pascal>/<Provider>Config.php` | yes | `final readonly class` config DTO |
| `src/Providers/<Pascal>/<Provider>NarrowedInput.php` | optional | Per-provider narrowed input (e.g. `TwilioNarrowedInput extends SmsSendInput`) |
| `src/Providers/<Pascal>/<Provider>Region.php` | optional | Regional cluster enum (e.g. `TwilioRegion: string`) |
| `src/Providers/<Pascal>/README.md` | yes | Per-connector README + frontmatter |
| `tests/Providers/<Pascal>/<Provider><Channel>ConnectorTest.php` | yes | PHPUnit spec |

## Readonly config classes (PHP 8.2+)

```php
final readonly class SendgridConfig
{
    public function __construct(
        public string $apiKey,
        public ?\Psr\Http\Client\ClientInterface $client = null,
    ) {}
}
```

`final readonly class` over PHP 8.0 `readonly` properties — the class-level form is the
PHP 8.2+ idiom and PHPStan level 8 narrows it correctly. Optional `$client` (PSR-18)
auto-discovers via `php-http/discovery` when omitted.

## Backed enums for closed sets

Native `enum`, not class constants:

```php
enum TwilioRegion: string {
    case Us1 = 'us1';  case Us2 = 'us2';  case Ie1 = 'ie1';  case Au1 = 'au1';
    case Br1 = 'br1';  case De1 = 'de1';  case Jp1 = 'jp1';  case Sg1 = 'sg1';
}
```

Used for: regional selectors, status enums (`EmailStatus`, `SmsStatus`), provider codes
(`ProviderCode`).

## `mapVendorError(int $status, mixed $body): ProviderCode` pattern

Each connector implements a private `mapVendorError(int $httpStatus, mixed $body): ProviderCode`.
Canonical baseline mapping (override per vendor when the response carries finer signal):

| HTTP status | Default `ProviderCode` |
|---|---|
| 400 | `InvalidRequest` |
| 401 | `AuthFailed` |
| 403 | `AuthFailed` (or `RateLimited` if vendor signals quota) |
| 404 | `InvalidRequest` |
| 422 | `InvalidRequest` (or `InvalidRecipient` if vendor flags address) |
| 429 | `RateLimited` |
| 5xx | `ProviderUnavailable` |
| network failure (PSR-18 `ClientExceptionInterface`) | `ProviderUnavailable` |
| unparseable | `Unknown` |

## `Retry-After` surfacing pattern

The wrapper does NOT carry a top-level structured `retryAfterSeconds` field on
`ConnectorError`. When the vendor sets `Retry-After`, the connector:

```php
$rawBody     = (string) $response->getBody();
$errorBody   = json_decode($rawBody, true) ?? null;
$retryAfter  = $response->getHeaderLine('Retry-After'); // empty string when absent

$cause = is_array($errorBody) ? $errorBody : [];
if ($retryAfter !== '') {
    $cause['retryAfter'] = $retryAfter;
}

$parsedSeconds = $retryAfter !== '' && is_numeric($retryAfter) ? (int) $retryAfter : null;
$providerMessage = $parsedSeconds !== null
    ? "Rate limited; retry after $parsedSeconds seconds"
    : 'Rate limited';

throw new ConnectorError(
    providerCode:    ProviderCode::RateLimited,
    providerMessage: $providerMessage,
    cause:           $cause,
    statusCode:      $response->getStatusCode(),
);
```

Spec tests assert:
- `assertStringContainsString('retry after 60 seconds', $e->providerMessage)`
- `assertSame('60', $e->cause['retryAfter'])` (string for header-based; **int** for Telegram body-based)
- **Do NOT** assert `$e->retryAfterSeconds` — that field does not exist.

## Casing locality

Vendor-specific wire-key casing (PascalCase for Twilio, snake_case for SendGrid v3,
camelCase for SparkPost, etc.) is written directly in the array literal that feeds
`BaseConnector::sendPostJson` / `sendPostForm`. **No shared casing transform.** Per
connector is the single source of truth for its wire mapping.

## Baseline-coverage discipline

When adding a connector: fields ≥90% of providers in that channel support natively go in
the normalized DTO; rarer fields go in `<Provider>NarrowedInput` extending the DTO, or
stay in `_passthrough`. Never implement missing vendor features; never add sub-baseline
fields to the normalized DTO to "help" a single provider.

## Wrapper-holds-no-state discipline

Connectors instantiated per-call are valid; instances hold config + injected clients
only. No in-class caches, no retry buffers, no idempotency-key generators. The FCM /
APNs `TokenCacheHook` is the **only** stateful seam, and the state lives in the
consumer-supplied hook, not the wrapper.

## Test pattern (PHPUnit 11 + `php-http/mock-client`)

`Http\Mock\Client` for the PSR-18 mock. Fixtures construct PSR-7 responses via
`Nyholm\Psr7\Response`. The shared fixture helpers live in `tests/Fixtures/`
(`RetryAfterFixtures`, `TokenCacheFixtures`).

```php
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridConfig;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridEmailConnector;

final class SendgridEmailConnectorTest extends TestCase
{
    #[Test]
    public function it_POSTs_to_v3_mail_send(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(202, ['X-Message-Id' => 'abc']));

        $connector = new SendgridEmailConnector(new SendgridConfig(apiKey: 'k', from: 'n@example.com'), $client);
        $result = $connector->send(new EmailSendInput(/* … */));

        self::assertTrue($result->success);
    }
}
```

Coverage ≥80%.

## Per-connector README YAML frontmatter schema

Required keys (top-level): `providerId`, `channel`, `auth`, `endpoint`, `versioning`,
`notes_passthrough`. Optional: `novuProviderId`, `regionalEndpoints`, `tier`,
`tokenCacheHookSupported`, `rateLimitDocsUrl`, `retryAfterSurfaced`. The authoritative
schema is at [`../schemas/connector-readme-schema.yaml`](../schemas/connector-readme-schema.yaml)
and is enforced by `scripts/validate-frontmatter.php` in CI.

The frontmatter block **must be the very first thing in the file** (opening `---` on
line 1), with the `# Title` heading *below* the closing `---`. GitHub only renders a
`---…---` block as frontmatter when it leads the file; place it under the title and
GitHub leaks the raw YAML into the rendered body. The CI validator scans past a leading
title to the first `---`, so it will **not** catch a misplaced block — keep it on top.

## PHP / lint / build

- `composer.json` requires `php: ^8.2`. `declare(strict_types=1)` on every file.
- PHPStan level 8 — zero errors. Run: `composer analyse`.
- PER-CS via PHP-CS-Fixer. Run: `composer cs-check` (read-only) or `composer cs-fix`.
- PHPUnit 11 with `#[Test]` attributes. Run: `composer test`.
- `composer validate-frontmatter` validates every `src/Providers/<Pascal>/README.md`
  against `schemas/connector-readme-schema.yaml`.
- No build step (no transpilation). Composer auto-loads via PSR-4 from `src/`.
