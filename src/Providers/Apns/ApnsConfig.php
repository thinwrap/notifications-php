<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Apns;

use Thinwrap\Notifications\Contract\TokenCacheHook;

/**
 * Configuration for the Apple Push Notification service (APNs) connector.
 *
 * Token-based auth (ES256 JWT): supply `keyId`, `teamId`, `bundleId`, and
 * `privateKey` (the `.p8` EC P-256 PEM). `production` selects the
 * `api.push.apple.com` host (default) vs the `api.sandbox.push.apple.com`
 * sandbox when `false`. Optional `tokenCache` hook amortizes the JWT
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
