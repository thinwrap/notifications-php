# `thinwrap/notifications` (PHP) — contributor guide

This folder (`.ai/`) is for developers — and the coding agents working alongside them — who are
**changing this library**: adding a connector or improving the package. It is not usage
documentation.

> **Using the package in your app?** See [`../README.md`](../README.md) and the per-connector
> READMEs under [`../src/Providers/`](../src/Providers). `.ai/` is not part of the Packagist dist —
> its only audience is people working in the repo.

## Map of this folder

- **guidelines.md** (this file) — entry point + the "add a connector" recipe.
- [`ARCHITECTURE.md`](./ARCHITECTURE.md) — the facade → dispatch → base model and the stateless-wrapper invariants every change must hold.
- [`CONVENTIONS.md`](./CONVENTIONS.md) — naming, file/namespace layout, error mapping, `_passthrough`, the per-connector README convention.
- [`TEST-FIXTURES.md`](./TEST-FIXTURES.md) — shared PHPUnit fixtures (`Retry-After`, `TokenCacheHook`).

## The shape in one sentence

A consumer constructs a channel facade by `NotificationProviderId`
(`new Email(NotificationProviderId::Sendgrid, $config)`); the facade dispatches to a connector under
`src/Providers/<Provider>/` that extends `BaseConnector` (in `src/Base/`), which centralizes the
PSR-18 request + JSON parsing + error mapping. No global middleware — vendor specifics stay local
to the connector.

## Setup & verify

```bash
composer install
composer test && composer analyse && composer cs-check
```

PHP ≥8.2, `declare(strict_types=1)` on every file. **Zero runtime deps / no vendor SDKs** —
consumers bring a PSR-18 client (resolved via `php-http/discovery`); SigV4 for SES/SNS is hand-rolled.

## Add a connector

Copy [`src/Providers/Sendgrid/`](../src/Providers/Sendgrid) as your template. Namespace is
`Thinwrap\Notifications\Providers\<Provider>`. Touch-points, in order:

1. **Register the id** — add the case to [`src/Enum/NotificationProviderId.php`](../src/Enum/NotificationProviderId.php). One id per vendor, no aliases.
2. **Create `src/Providers/<Provider>/`**:
   - `<Provider>Config.php` — `final readonly class` (auth field first; optional PSR-18 `$client`).
   - `<Provider><Channel>Connector.php` — `final class … extends BaseConnector`, `send(...)`, private `mapVendorError(...)`.
   - `<Provider>NarrowedInput.php` — only when the provider exposes sub-baseline fields (extends the channel input DTO).
   - `README.md` — plain Markdown that opens directly with its `# Title` (no YAML metadata block). **This** is the connector's consumer doc; keep it complete and at parity with the sibling-language libraries.
3. **Dispatch** — add the `match` arm to the channel facade (`src/Email.php`, `src/Sms.php`, `src/Push.php`, or `src/Chat.php`).
4. **Test** — `tests/Providers/<Provider>/<Provider><Channel>ConnectorTest.php`; PHPUnit with `#[Test]`, mock the PSR-18 client (`Http\Mock\Client` + `Nyholm\Psr7\Response`); reuse the `Retry-After` / `TokenCacheHook` fixtures (see TEST-FIXTURES).

### Definition of done (the CI gates)

```bash
composer test                 # PHPUnit; ≥80% line-coverage gate
composer analyse              # PHPStan level 8, zero errors
composer cs-check             # PHP-CS-Fixer (PER-CS); composer cs-fix to apply
```

CI runs these on PHP 8.2 / 8.3 / 8.4, plus an offline import smoke (zero construct-time egress),
license + no-vendor-SDK + no-lifecycle-script gates.

## Invariants you must not break

Full reasoning lives in [`ARCHITECTURE.md`](./ARCHITECTURE.md); the short list:

- **Zero runtime deps / no vendor SDKs.**
- **Stateless wrapper.** No caching, retries, idempotency keys, or telemetry. FCM/APNs sign fresh unless the consumer passes a `TokenCacheHook` (epoch in **seconds** — a deliberate divergence from the TS lib's milliseconds).
- **≥90% baseline-coverage rule.** A field belongs on the base channel input only if ≥90% of that channel's providers support it; everything else goes to `_passthrough` (input) / `raw` (output) or a narrowed input class.
- **Per-connector locality.** `mapVendorError` and outlier translations live inside `src/Providers/<Provider>/` — never in `BaseConnector`.
- **Six `ProviderCode` cases**, byte-identical to the TS lib, surfaced via `ConnectorError`. **No top-level `retryAfterSeconds`** — `cause = {raw, retryAfter, retryAfterSeconds?}`.
