# `thinwrap/notifications` (PHP) — Architecture

One-page summary of the facade-dispatch-base pattern, the 6-case `ProviderCode` model,
and the stateless-wrapper invariants.

## Why facade + dispatch + base

Three layers. Consumer constructs a channel facade by `NotificationProviderId`; the
facade dispatches to a specific connector class; the connector extends `BaseConnector`,
which centralizes HTTP + JSON parsing + error mapping. No global middleware.

```
Consumer code
    │  new Email(NotificationProviderId::Sendgrid, $cfg)
    ▼
Email facade ─── match ($providerId) ────► SendgridEmailConnector
    │  ->send($input)                              │  extends BaseConnector
    ▼                                              ▼
$connector->send($input)                     BaseConnector::sendPostJson($url, $body, $opts)
                                                   │
                                                   ▼  PSR-18 ClientInterface (BYO via $cfg->client)
                                              Vendor API
```

## `NotificationProviderId`-based dispatch

Provider routing uses the backed enum `Thinwrap\Notifications\Enum\NotificationProviderId`.
Channel membership is enforced by each facade's `match` arms — the enum itself is
channel-agnostic. Adding a connector = one new enum case + one new `match` arm in the
owning channel facade + a per-provider `<Provider>Config` + a `<Provider><Channel>Connector`.

PHPStan level 8 + `@phpstan-assert-if-true` on each facade's union-narrowing predicate
gives the equivalent of TypeScript's compile-time narrowing — mismatched
`(NotificationProviderId, *Config)` pairings fail static analysis.

## Repo layout

| Path | Purpose |
|---|---|
| `src/Base/BaseConnector.php` | shared PSR-18 plumbing (`sendGet`, `sendPostJson`, `sendPostForm`) |
| `src/Email.php`, `Sms.php`, `Push.php`, `Chat.php` | top-level channel facades |
| `src/Contract/<Channel>ConnectorInterface.php` | the four channel contracts |
| `src/Contract/TokenCacheHook.php` | optional FCM/APNs consumer-owned cache seam |
| `src/DTO/<Channel>/<Channel>Send{Input,Result}.php` | normalized channel DTOs |
| `src/Enum/NotificationProviderId.php` | backed string enum, one case per provider |
| `src/Enum/ProviderCode.php` | 6-case canonical error enum |
| `src/Exception/ConnectorError.php` | unified error type |
| `src/Util/Passthrough.php` | 2-arg `merge($base, $passthrough)` helper |
| `src/Providers/<Pascal>/<Provider>Config.php` | per-provider config (readonly class) |
| `src/Providers/<Pascal>/<Provider><Channel>Connector.php` | per-provider connector |
| `src/Providers/<Pascal>/<Provider>NarrowedInput.php` | optional per-provider narrowed input |
| `src/Providers/<Pascal>/README.md` | per-connector consumer doc (plain Markdown) |
| `tests/Providers/<Pascal>/` | per-connector PHPUnit specs |
| `tests/Fixtures/` | PSR-7 fixture helpers (Retry-After, `TokenCacheHook` spies) |
| `.github/workflows/ci.yml` | PHP 8.2/8.3/8.4 × Ubuntu matrix |
| `.github/workflows/release.yml` | Packagist webhook + cosign-keyless signing |

## 6-case `ProviderCode`

Six cases, byte-exact across TypeScript and PHP packages. String backing values match
the TypeScript union literals:

| PHP enum case | Backing string |
|---|---|
| `ProviderCode::InvalidRecipient` | `'invalid_recipient'` |
| `ProviderCode::RateLimited` | `'rate_limited'` |
| `ProviderCode::AuthFailed` | `'auth_failed'` |
| `ProviderCode::ProviderUnavailable` | `'provider_unavailable'` |
| `ProviderCode::InvalidRequest` | `'invalid_request'` |
| `ProviderCode::Unknown` | `'unknown'` |

Six, not 11 like the location-php sibling. Additions require a coordinated TypeScript +
PHP change.

## `ConnectorError` shape

```php
final class ConnectorError extends \RuntimeException
{
    public function __construct(
        public readonly ProviderCode $providerCode,
        public readonly ?string $providerMessage = null,
        public readonly mixed $cause = null,
        public readonly ?int $statusCode = null,
    ) {}
}
```

There is **no** top-level structured `retryAfterSeconds` field. Retry-After surfaces via `$e->cause['retryAfter']`
(raw header string for most providers; **int** for Telegram's body-based form) and the
parsed seconds woven into `$e->providerMessage`.

## Baseline-coverage discipline (≥90% rule)

The normalized channel DTOs (`EmailSendInput`, `SmsSendInput`, `PushSendInput`,
`ChatSendInput`) include only fields ≥90% of providers in that channel support natively.
Sub-90% fields go to `_passthrough` (input) or `raw` (output).

## Architectural-outlier exception

Single-provider misses do not disqualify a field from baseline; the connector translates
locally at the wire layer. Notable outliers:

- **Pusher Beams** — synthesizes APNs `aps` + FCM `notification` payloads at the wire layer.
- **SparkPost** — CC/BCC via `recipients[].header_to` + `content.headers.CC`.
- **Brevo** — throws on attachment `contentId` (vendor doesn't support inline CIDs).
- **Telegram** — Retry-After in the JSON body's `parameters.retry_after` (int), not the header.

## Per-connector locality (casing + error mapping)

`mapVendorError(int $status, mixed $body): ProviderCode`, casing transforms
(snake_case / PascalCase / camelCase keys), and outlier translations all live **inside**
each `src/Providers/<Pascal>/` directory. No global middleware. No automatic casing
transform at `BaseConnector` level — vendor wire shapes are written directly in array
literals.

## Stateless wrapper + optional `TokenCacheHook`

The wrapper holds no state: no token cache, no connection pool, no retry buffer.
Connectors instantiated per-call are valid; instances hold config + injected clients only.

FCM (JWT RS256 + OAuth2 exchange) and APNs (JWT ES256) sign-on-every-send by default
(stateless). The optional `TokenCacheHook` config field on `FcmConfig` / `ApnsConfig` is
the **only** stateful seam, and the state lives in the consumer-supplied hook —
not the wrapper. Epoch is **seconds** (PHP idiomatic `time()`), divergent from the
TypeScript sibling's milliseconds.

No retries. No idempotency-key generation. No in-wrapper telemetry.

## `Passthrough::merge` (2-arg form)

```php
Passthrough::merge(
    [
        'body'    => [/* connector-supplied */],
        'headers' => [/* connector-supplied */],
        'query'   => [/* connector-supplied */],
    ],
    $consumer_passthrough, // ?array{body?:..., headers?:..., query?:...}
): array{body:..., headers:..., query:...};
```

Body merges **deep**, headers and query merge **shallow**, consumer values win on
conflict (last-write-wins). Divergent from location-php's 4-arg form (deliberate; the
notifications scope has narrower call sites).

## Cross-reference

- Naming, file layout, test patterns: [`./CONVENTIONS.md`](./CONVENTIONS.md)
- Adding a connector / contributor entry point: [`./guidelines.md`](./guidelines.md)
- Consumer usage (install, send, error handling): [`../README.md`](../README.md)
