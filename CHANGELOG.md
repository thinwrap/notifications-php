# Changelog

All notable changes to `thinwrap/notifications` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] — 2026-10-18

### Added

- Twilio: `TwilioConfig` `edge` (`TwilioEdge`) picks the edge location; ie1 and au1 default to dublin and sydney.
- Sinch: `SinchRegion` `Au`, `Br` and `Ca`.
- SendGrid, Mailgun, Postmark, Mailtrap and MailerSend narrowed inputs send their typed fields to the vendor.
- Postmark: a `TemplateId` / `TemplateAlias` send goes to `/email/withTemplate`.
- Configs, and connectors holding them, show secrets as `[redacted]` under `var_dump` / `print_r`.
- Mailgun `oTracking` / `oTrackingClicks` accept `'htmlonly'`.

### Changed

- SES sends attachments (inline included) and custom headers through `Content.Simple`, so SES builds the MIME.
- SES rejects headers it sets itself and tags outside its charset before sending; non-ASCII header values go as RFC 2047 encoded-words.
- Resend rejects tags outside its charset before sending.
- Mailtrap drops `subject` / `text` / `html` on a `template_uuid` send and rejects `category` with it.
- APNs `TooManyProviderTokenUpdates` is `auth_failed` and names `tokenCache`.
- **Breaking:** Mailgun sends an inline image's part filename as its `contentId`, since Mailgun derives the Content-ID from it.
- **Breaking:** Pusher Beams follows its publish API table: 422 is `invalid_request` (was `invalid_recipient`), 402 `rate_limited`, 403 `auth_failed`, 404 `invalid_request`.
- **Breaking:** a header value that is not a string or scalar is `invalid_request`; scalars are sent as strings.
- **Behaviour change:** OneSignal: a 200 with a notification `id` is a success even when `errors` lists invalid recipients (they stay on `raw`); it used to throw `invalid_recipient`. 403 is `auth_failed`.
- **Behaviour change:** Postmark 300 is `invalid_recipient` only when the message names a quoted `'To'` / `'Cc'` / `'Bcc'` field; other 300s (e.g. "Zero recipients specified", a `'From'` or `'Reply-To'` address) are now `invalid_request`.
- Postmark: an empty `TemplateAlias` does not route, and a non-numeric `templateId` is sent as `TemplateAlias`.
- SendGrid rejects more than 10 categories or one over 255 characters (counted as characters, not bytes), and de-duplicates them by exact string.
- MailerSend `settings` accepts its snake_case keys too and rejects non-boolean values and unknown keys; a `personalization` entry without `data` is `invalid_request`.
- FCM rejects a message with no token, topic, condition or fid before sending.
- Postmark rejects `templateId` with `templateAlias`, and a `trackLinks` outside its enum; Mailgun rejects an unknown tracking string.
- SparkPost 420 (sending limit) is `rate_limited`; Mailgun 403 is `auth_failed`.
- Telegram 403 is `invalid_recipient` and 404 is `auth_failed`.
- Postmark, Telnyx and WhatsApp map the vendor's documented error codes.
- SES reads the error code from `x-amzn-ErrorType`; `MessageRejected` is `invalid_request`, `SendingPausedException` is `auth_failed`.

### Deprecated

- `TwilioRegion` `Us2`, `Br1`, `De1`, `Jp1` and `Sg1` (edge locations, not REST regions); they are rejected before sending.
- `MailersendNarrowedInput::$variables`; MailerSend removed it and it is not sent.

### Fixed

- A header with CR/LF (e.g. an API key with a trailing newline) or an invalid URL is an `invalid_request` `ConnectorError` that never carries the value.
- Config values placed in a URL path (Sinch `servicePlanId`, Twilio SID, Plivo auth id, FCM project, WhatsApp ids, Telegram token, Beams instance) are path-escaped.
- Twilio ie1/au1 use the current `api.{edge}.{region}.twilio.com` hosts; a deprecated region is rejected even with an `edge`.
- SES, SNS and Pusher Beams reject a region / instance id that is not a host label before sending.
- Infobip rejects a `baseUrl` that is not a bare hostname before sending.
- Sender display names are quoted or RFC 2047-encoded in SES, Resend, Postmark and Mailgun `from`.
- Resend sends `content_id`; SparkPost names inline images by Content-ID and always sends `type`; MailerSend no longer sends a nonexistent `content_type`.
- Mailgun sends passthrough maps as JSON and never emits a header twice.
- Mailtrap treats an empty `inboxId` as missing.
- APNs infers the push type from the final payload (`alert` for an alert, badge or sound) or the caller's `apns-push-type` header, sends background pushes with priority 5 and `content-available`, keeps a typed string `aps.alert`, and never sends `"aps":[]` or `"alert":[]`.
- Pusher Beams data-only pushes send no empty `notification` / `alert`, and iOS receives `data`.
- FCM drops `token` when a topic, condition or fid is given, and maps OAuth 429/5xx to `rate_limited` / `provider_unavailable`.
- APNs, FCM and Pusher Beams map every 5xx to `provider_unavailable`.
- A missing PSR-17 factory is a `ConnectorError` instead of a discovery exception.
- A header with NUL is rejected like CR/LF, and a scalar header value no longer raises a `TypeError`.
- Region, host and id validators match the whole value, so a trailing newline is `invalid_request`.
- Empty object fields (Postmark `TemplateModel`/`Metadata`, SendGrid settings and template data, Mailtrap variables, MailerSend `data`, Mailgun `t:variables`) are sent as `{}`, not `[]`.
- SendGrid, Mailgun and MailerSend template sends leave out an empty `subject`, so the template's subject applies.
- Mailgun `vCustomVars` map values are sent as JSON.
- MS Teams: a Workflows webhook's empty `202 Accepted` counts as success instead of a `provider_unavailable` error.
- OneSignal: `os_v2_` App API keys use `api.onesignal.com` with `Authorization: Key`, and passthrough targeting replaces the default recipient.
- WonderPush: `data`, `sound`, `badge` and `subtitle` go to `push.custom` / `alert.ios` / `alert.android` as the notification object defines them.
- Infobip: a 200 with a `REJECTED` message status throws; `sendAt`, `language` and `validityPeriod` follow the v2 message shape.
- MailerSend: an `ALL_SUPPRESSED` 202 throws `invalid_recipient`, and baseline `headers` are sent.
- Credentials no longer appear in exception stack-trace arguments (`#[\SensitiveParameter]`).

### Documentation

- The error contract is stated precisely: native `\TypeError` / `\ValueError` for what PHP enforces, `ConnectorError` for everything else.
- APNs `tokenCache` is required in production.
- README: Symfony HttpClient's cross-host redirect behaviour; the Sinch constructor order; Telnyx/Plivo/WhatsApp config fields; WonderPush Bearer auth.
- The Packagist dist no longer contains `tests/`, `.ai/` or the repo tooling files.

## [1.1.1] — 2026-08-08

### Fixed

- A PSR-18 client that throws on a non-2xx no longer collapses every provider error into
  `provider_unavailable` with no status; status mapping and `Retry-After` run as before.

### Documentation

- The PSR-18 non-2xx contract is now stated in the README, not only in `.ai/`.

## [1.1.0] — 2026-07-24

### Fixed

- **APNs now sends over HTTP/2, which Apple's push service requires.** The
  request's HTTP version is pinned to `2.0` via a new optional
  `$protocolVersion` argument on the base connector's POST helpers, so PSR-18
  clients that otherwise default to HTTP/1.1 (e.g. Guzzle) negotiate h2 —
  previously every APNs push failed against an HTTP/1.1-only transport.
- **Connector hardening (second/third review pass)** — error-classification and
  request/response corrections across the email, SMS, push, and chat connectors.

### Changed

- **Per-connector READMEs normalised** to one consistent structure across the
  notification providers (documentation only; no API change).

## [1.0.1] — 2026-07-20

### Changed

- **Per-connector READMEs** no longer carry a YAML frontmatter block — the
  metadata GitHub rendered as a table but nothing consumed. The frontmatter
  validator (`scripts/validate-frontmatter.php`), its schema, and the CI gate
  that ran it have been removed. Per-connector docs are now plain Markdown.

### Fixed

- **SES raw-MIME conformance** — attachment base64 is line-wrapped at 76
  characters (RFC 5322, avoiding the 998-octet line limit) and the text/plain &
  text/html parts declare `Content-Transfer-Encoding: 8bit` (was `7bit`) over
  their UTF-8 bodies.
- **FCM configuration** validates the service-account array and throws
  `ConnectorError` (`InvalidRequest`) when `project_id` / `client_email` /
  `private_key` are missing, instead of emitting a PHP warning followed by a
  `TypeError`.

### Security

- **SNS** transport errors now route through the hardened `dispatch()` path, so
  only the exception class — never the raw transport message — is surfaced on
  `providerMessage` / `cause`, matching every other connector.

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
