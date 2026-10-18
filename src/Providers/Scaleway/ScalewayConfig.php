<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Scaleway;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Scaleway TEM connector configuration. Mirrors TS
 * (`notifications-ts/src/providers/scaleway/scaleway.config.ts`).
 *
 * `$secretKey` is the Scaleway IAM API secret key, sent verbatim in the
 * `X-Auth-Token` header (NOT Bearer/Authorization). `$projectId` is REQUIRED
 * and written as the `project_id` wire field on every send. `$region` (default
 * `fr-par`) selects the EU cluster and is interpolated into the endpoint path.
 */
final readonly class ScalewayConfig
{
    use RedactsSecrets;

    public function __construct(
        #[\SensitiveParameter]
        public string $secretKey,
        public string $projectId,
        public string $from,
        public ?string $senderName = null,
        public ?ScalewayRegion $region = null,
    ) {}
}
