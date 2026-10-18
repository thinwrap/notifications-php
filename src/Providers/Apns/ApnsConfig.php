<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Apns;

use Thinwrap\Notifications\Contract\TokenCacheHook;
use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Configuration for the Apple Push Notification service (APNs) connector.
 *
 * Token-based auth (ES256 JWT): supply `keyId`, `teamId`, `bundleId`, and
 * `privateKey` (the `.p8` EC P-256 PEM). `production` selects the
 * `api.push.apple.com` host (default) vs the `api.sandbox.push.apple.com`
 * sandbox when `false`.
 *
 * `tokenCache` is optional in the type but required in production: APNs
 * rejects a new provider token more than once every 20 minutes
 * (`429 TooManyProviderTokenUpdates`), and without a cache every send signs a
 * new one. See the `TokenCacheHook` contract.
 */
final readonly class ApnsConfig
{
    use RedactsSecrets;

    public function __construct(
        public string $keyId,
        public string $teamId,
        public string $bundleId,
        #[\SensitiveParameter]
        public string $privateKey,
        public bool $production = true,
        public ?TokenCacheHook $tokenCache = null,
    ) {}
}
