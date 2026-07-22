# D7 Networks Sms Connector (PHP)

## Quick install

See the [package README](../../../README.md) for installation. This connector dispatches when `NotificationProviderId::D7networks` is passed to the `Sms` facade.

```php
use Thinwrap\Notifications\Sms;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Providers\D7networks\D7networksConfig;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

$cfg = new D7networksConfig(/* see config shape below */);
$sms = new Sms(NotificationProviderId::D7networks, $cfg);
$result = $sms->send(new SmsSendInput(to: '+15551234567', body: 'Hello, world!'));
```

## Configuration

`D7networksConfig(token: string)`

Bring-your-own PSR-18 client + PSR-17 factories via the facade constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Bearer token from D7 dashboard. Sent as `Authorization: Bearer <token>`.

## Endpoint

`https://api.d7networks.com/messages/v1/send`

## Narrowed input augmentations

Standard SMS input (`to`, `body`, `from`) applies. D7 has the most nested wire shape of any SMS provider: the connector builds `messages: [{ channel: "sms", recipients: [<to>], content: <body>, msg_type: "text" }]` and `message_globals: { originator: <from> }`. The originator resolves from `$input->originator` → `$input->from` → `config.from` (**required** — a missing originator raises `ConnectorError(InvalidRequest)` before any HTTP call). On success `status` is `Sent` and `providerMessageId` comes from `request_id`.

`D7networksNarrowedInput` extends `SmsSendInput` with below-baseline D7 fields — `originator`, `tag`, `dataCoding`, `scheduleTime`, `validityPeriod`, `reportUrl`, `msgType` — which the connector allocates to the correct nesting level (per-message vs `message_globals`) and writes in D7's snake_case wire form (`data_coding`, `schedule_time`, `validity_period`, `report_url`, `msg_type`). Any further D7-specific fields go via `_passthrough['body']` in wire (snake_case) casing, including nested `message_globals` entries.

## Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 / 403 | (any) | `AuthFailed` |
| 429 | (any) | `RateLimited` |
| ≥500 | (any) | `ProviderUnavailable` |
| other ≥400 | (any) | `InvalidRequest` |
| — | (unmatched, <400) | `Unknown` |

The error body shape is `{code, detail}`; both are surfaced in `$e->providerMessage` and preserved on `$e->cause['raw']`.

The connector surfaces 429 via `ConnectorError(providerCode: RateLimited)`; the raw `Retry-After` header (when present) is on `$e->cause['retryAfter']` and parsed seconds are woven into `$e->providerMessage` (and also on `$e->cause['retryAfterSeconds']`). There is no top-level `retryAfterSeconds` field — the wrapper never retries.

## `_passthrough` examples

Forward D7-specific fields (e.g. `originator`, `tag`, `report_url`) via `_passthrough.body`.

## Vendor docs

- API reference: <https://d7networks.com/docs/sms/send-sms/>
