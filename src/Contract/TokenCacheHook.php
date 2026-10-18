<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Contract;

/**
 * Caller-supplied hook for reusing the JWT / OAuth token across sends
 * (consumed by the FCM and APNs connectors).
 *
 * The wrapper holds no state; FCM/APNs sign fresh on every send unless a
 * consumer plugs in a `TokenCacheHook` implementation. For APNs that is
 * required in production: APNs rejects a new provider token more than once
 * every 20 minutes (`429 TooManyProviderTokenUpdates`).
 * Cache entry shape:
 * `['token' => string, 'expiresAt' => int (UNIX epoch SECONDS)]`.
 *
 * NOTE on cross-language divergence: PHP epoch is SECONDS (`time()`); the TS
 * counterpart uses MILLISECONDS (`Date.now()`). Intentional PHP idiom —
 * an accepted, documented divergence.
 */
interface TokenCacheHook
{
    /**
     * Read the cached token for the given cache key. Caller is responsible for
     * pre-checking expiry; implementations MAY return null for an expired entry.
     *
     * @return array{token:string,expiresAt:int}|null
     */
    public function get(string $cacheKey): ?array;

    /**
     * Persist the token entry. Implementations MAY ignore writes (e.g. read-only
     * caches) — connectors do not assume `get()` after `set()` returns the same
     * entry.
     *
     * @param array{token:string,expiresAt:int} $entry
     */
    public function set(string $cacheKey, array $entry): void;
}
