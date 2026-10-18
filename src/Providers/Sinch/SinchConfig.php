<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sinch;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Sinch SMS connector configuration. Bearer auth — `$apiToken` is sent as
 * `Authorization: Bearer <apiToken>`. `$region` selects between US (default)
 * and EU clusters.
 */
final readonly class SinchConfig
{
    use RedactsSecrets;

    public function __construct(
        public string $servicePlanId,
        #[\SensitiveParameter]
        public string $apiToken,
        public ?string $from = null,
        public ?SinchRegion $region = null,
    ) {}
}
