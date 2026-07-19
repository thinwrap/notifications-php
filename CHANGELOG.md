# Changelog

All notable changes to `thinwrap/notifications` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Per-connector READMEs** no longer carry a YAML frontmatter block — the
  metadata GitHub rendered as a table but nothing consumed. The frontmatter
  validator (`scripts/validate-frontmatter.php`), its schema, and the CI gate
  that ran it have been removed. Per-connector docs are now plain Markdown.

## [1.0.0] — 2026-06-04

First public release of `thinwrap/notifications` — the lightweight, SDK-free
notifications wrapper for Email, SMS, Push, and Chat across 35 providers.

### Public surface (locked at v1.0)

- **4 unified facades** in the `Thinwrap\Notifications\` namespace:
  `Email`, `Sms`, `Push`, `Chat`. Single `send($input): <Channel>SendResult`
  method per facade.
- **35 per-provider connectors** under `Thinwrap\Notifications\Providers\<Pascal>\`:
  - **Email (10)**: `SesEmailConnector`, `ResendEmailConnector`,
    `MailgunEmailConnector`, `SendgridEmailConnector`, `PostmarkEmailConnector`,
    `MailersendEmailConnector`, `MailtrapEmailConnector`, `BrevoEmailConnector`,
    `SparkpostEmailConnector`, `ScalewayEmailConnector`.
  - **SMS (10)**: `VonageSmsConnector`, `TwilioSmsConnector`, `PlivoSmsConnector`, `SnsSmsConnector`,
    `SinchSmsConnector`, `TelnyxSmsConnector`, `InfobipSmsConnector`,
    `MessagebirdSmsConnector`, `TextmagicSmsConnector`, `D7networksSmsConnector`.
  - **Push (6)**: `FcmPushConnector`, `ExpoPushConnector`, `ApnsPushConnector`,
    `OneSignalPushConnector`, `PusherBeamsPushConnector`, `WonderpushPushConnector`.
  - **Chat (9)**: `TelegramChatConnector`, `SlackChatConnector`,
    `WhatsappBusinessChatConnector`, `DiscordChatConnector`, `MsteamsChatConnector`,
    `GoogleChatChatConnector`, `MattermostChatConnector`, `RocketChatChatConnector`,
    `LineChatConnector`.
- **Error model**: `Thinwrap\Notifications\Exception\ConnectorError`
  (extends `\RuntimeException`) + 6-value `Thinwrap\Notifications\Enum\ProviderCode`
  string-backed enum (`invalid_recipient`, `rate_limited`, `auth_failed`,
  `provider_unavailable`, `invalid_request`, `unknown`).
- **DTOs**: per-channel `<Channel>SendInput` and `<Channel>SendResult` under
  `Thinwrap\Notifications\DTO\<Channel>\`. Per-provider narrowed-input classes
  co-located with each connector.
- **Provider identification**: `Thinwrap\Notifications\Enum\NotificationProviderId`
  backed string enum, 35 cases (one per connector). No Novu `nexmo` alias is
  carried — `Vonage` is the single canonical id for the Vonage/Nexmo provider.
- **Optional `TokenCacheHook`** (`Thinwrap\Notifications\Contract\TokenCacheHook`) for
  FCM (`fcm:<projectId>` cache key) and APNs (`apns:<teamId>:<keyId>:<bundleId>` cache
  key). 2-method interface (`get` / `set`); epoch is seconds.
- **Passthrough utility**: `Thinwrap\Notifications\Util\Passthrough::merge` — 2-arg
  helper (`merge($base, $passthrough)`) with deep body / shallow headers + query.

### Properties

- **Zero vendor SDK dependencies** — uses PSR-18 HTTP client (BYO via
  `php-http/discovery` or explicit injection through `*Config::client`).
- **Cosign keyless provenance** — every release source archive is cosign-signed via
  GitHub OIDC; no static private key, no long-lived Packagist token. PHP analogue of
  npm Sigstore provenance.
- **Wrapper holds no state** — no token cache, no connection pool, no retry buffer.
  Every operation is a single method call from input to output. Consumers compose
  retry / caching / lifecycle out-of-band. FCM and APNs optionally take a
  consumer-owned `TokenCacheHook`; the wrapper signs fresh by default.
- **Bring-your-own PSR-18 client** — pass any PSR-18-compatible client (Guzzle,
  Symfony HttpClient, php-http/curl-client, …) for tracing, mocking, or custom
  transport.
- **PHPStan level 8 clean** on the public surface. Full readonly DTOs with promoted
  constructor properties (PHP 8.2+).
- **PHP ≥8.2** — uses `final readonly class`, backed enums, native union types, and
  `mixed` for the connector-error `$cause` payload.
- **No automatic retry**. There is no top-level `retryAfterSeconds` field on
  `ConnectorError`. `$e->cause` carries the raw vendor response
  (`$e->cause['raw']`), the raw `Retry-After` value (`$e->cause['retryAfter']`),
  and parsed seconds (`$e->cause['retryAfterSeconds']`) where the vendor
  provides one.
- **Cross-language parity** with `@thinwrap/notifications` (npm) at the normalized
  facade surface — identical facade names, `ConnectorError` shape, `ProviderCode`
  enum values, per-channel input/result DTOs, and 35 provider IDs. Deliberate
  divergences (Novu drop-in compat TS-only; `TokenCacheHook` epoch units;
  `_passthrough` leading-underscore).

### Baseline-coverage discipline

The unified facade surface includes only features ≥90% of providers in each channel
natively support. Sub-baseline fields are accessible via per-provider narrowed input
DTOs and the `_passthrough` escape hatch.

### Cross-language

Companion package `@thinwrap/notifications` publishes simultaneously on npm with
identical facade names, error model, result shapes, and 35 provider IDs.

### Migration

This is the first public release under the `thinwrap/notifications` name; there are
no prior published versions. Novu drop-in compatibility is intentionally a
TypeScript-only feature; the PHP package does not implement Novu's provider
interfaces.

The README's Migration section covers shifting from vendor PHP SDKs
(`twilio/sdk`, `sendgrid/sendgrid-php`, `mailgun/mailgun-php`, `aws/aws-sdk-php` for
SES/SNS) and hand-rolled PSR-18 HTTP.

[1.0.0]: https://github.com/thinwrap/notifications-php/releases/tag/v1.0.0
