<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Apns;

use Thinwrap\Notifications\Contract\TokenCacheHook;

/**
 * Stub config for Apple Push Notification service. Real shape lands in
 * a later release.
 *
 * Token-based auth (preferred): supply `keyId`, `teamId`, `bundleId`, and
 * `privateKey` (P8 PEM). Optional `tokenCache` hook amortizes the JWT
 * signing — see `TokenCacheHook` contract.
 */
final readonly class ApnsConfig
{
    public function __construct(
        public string $keyId,
        public string $teamId,
        public string $bundleId,
        public string $privateKey,
        public bool $production = true,
        public ?TokenCacheHook $tokenCache = null,
    ) {}
}
