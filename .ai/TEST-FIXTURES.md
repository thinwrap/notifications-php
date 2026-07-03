# Test-fixture conventions — notifications-php

Documents the Retry-After PSR-7 fixture convention, the `TokenCacheHook` mock
fixture convention, and the PSR-7 implementation choice so future contributors
don't reinvent them.

## Retry-After fixture convention

Every connector whose vendor returns 429 (or 503) with a `Retry-After` header
ships at least one PHPUnit test using the helpers in
`tests/Fixtures/RetryAfterFixtures.php`.

### Canonical dual-assertion shape

The wrapper performs no retry; it surfaces Retry-After in two slots — parsed
seconds woven into the human-readable
message and the raw header value on the error's `cause` payload. Tests assert
**both**:

```php
use Thinwrap\Notifications\Tests\Fixtures\RetryAfterFixtures;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Enum\ProviderCode;

public function testSurfacesRetryAfterOn429(): void
{
    $mockClient = new \Http\Mock\Client();
    $mockClient->addResponse(RetryAfterFixtures::responseWithRetryAfterSeconds(60));

    $connector = new TwilioSmsConnector(new TwilioConfig(/* … */), $mockClient);

    try {
        $connector->send(new SmsSendInput(/* … */));
        $this->fail('Expected ConnectorError');
    } catch (ConnectorError $e) {
        self::assertSame(ProviderCode::RateLimited, $e->providerCode);
        self::assertStringContainsString('retry after 60 seconds', (string) $e->providerMessage);
        self::assertSame('60', $e->cause['retryAfter']);
    }
}
```

### Special case — Telegram (body-based int)

Telegram emits `retry_after` as an integer in the JSON response body at
`parameters.retry_after`, NOT a header. Two test deltas:

- Fixture: `responseWithRetryAfterSeconds(30, 429, ['ok' => false, 'parameters' => ['retry_after' => 30]])`
- Assertion: `assertSame(30, $e->cause['retryAfter'])` — **int**, not string.

Type divergence is deliberate.

### Do NOT assert `$e->retryAfterSeconds`

The `ConnectorError` class has no top-level `retryAfterSeconds` field. Any drift
in PR diff that introduces the structured field must be rejected on review.

## `TokenCacheHook` fixture convention

Two connectors at v1.0 consume `Thinwrap\Notifications\Contract\TokenCacheHook`:

- **FCM** — cache key shape `fcm:<projectId>`
- **APNs** — cache key shape `apns:<teamId>:<keyId>:<bundleId>`

The five canonical paths exercised by `tests/Fixtures/TokenCacheFixtures.php`:

| Path | Fixture | Assertion shape |
|---|---|---|
| cache-miss | `TokenCacheFixtures::cacheMiss()` | `setCalls === 1`, `getCalls === 1` |
| cache-hit | `TokenCacheFixtures::cacheHit($token, $expiresAt)` | `setCalls === 0`; outgoing `Authorization` header carries `Bearer $token` |
| cache-stale-hit | `TokenCacheFixtures::cacheStaleHit()` | `setCalls === 1` (signs fresh on stale read) |
| vendor-rejects | `TokenCacheFixtures::vendorRejectsCachedToken()` + 401 mock response | `ConnectorError(AuthFailed)` thrown; wrapper does NOT call evict (the wrapper holds no state) |
| stateless-by-default | NO hook supplied on `*Config` | every send triggers a fresh OAuth/JWT mock exchange |

### Hook contract is 2-method

The `TokenCacheHook` interface has exactly `get` and `set` methods (no
`delete`). Some earlier draft documents mention three methods — that was
specification drift; the implementation is the source of truth. Tests assert
on `getCalls` + `setCalls` only.

### Epoch is seconds (PHP), not milliseconds (TS)

Cache entries use `expiresAt: int` in UNIX-epoch **seconds** per PHP idiom
(`time()`). The TypeScript sibling uses milliseconds (`Date.now()`). Cross-
language divergence is intentional — language-idiomatic timestamps over forced parity at the
timer-tick level.

### Hand-rolled spy over `createMock`

`SpyingTokenCacheHook` is a `final class implements TokenCacheHook` with
public counters. Preferred over PHPUnit's `createMock(TokenCacheHook::class)`
for three reasons:

1. Direct property reads (`$mock->setCalls === 1`) over expectation matchers.
2. State-at-end-of-test fits the connector flow (call `get`, conditionally
   call `set`).
3. PHPStan level 8 clean end-to-end; `MockObject` returns are dynamically
   typed in PHPUnit's typestub-poor regions.

## PSR-7 implementation choice — `nyholm/psr7`

This repo standardizes on `nyholm/psr7` as the PSR-7 factory for test
fixtures. Wired in `composer.json` `require-dev`.

Reasoning:
- **Smaller surface** (~1k LOC vs guzzlehttp/psr7's ~3k LOC).
- **No Guzzle coupling.** Consumers using `symfony/http-client` or
  `php-http/curl-client` don't see Guzzle in `composer.lock`.
- **PSR-17 factories bundled** — `Nyholm\Psr7\Factory\Psr17Factory` covers
  request/response/uri/stream factories without a separate dev-dep.

Consumers' production PSR-7 implementation is unconstrained — the wrapper
accepts any `ResponseInterface`.

## Cross-language audit ties

The TS↔PHP parity analysis and the ≥90% baseline-coverage tabulation are kept
as design-record references (regenerated on real change), not CI gates.

## When to update this file

Coordinated changes touching multiple connector test files — landing the
convention update + the per-test adoption in one PR is canonical. If a new
connector adds a vendor-side Retry-After signal in a previously-unseen
shape, document the third assertion-pattern variant here BEFORE landing the
connector test.
