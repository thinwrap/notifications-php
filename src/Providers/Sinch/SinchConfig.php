<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sinch;

/**
 * Sinch SMS connector configuration. Bearer auth — `$apiToken` is sent as
 * `Authorization: Bearer <apiToken>`. `$region` selects between US (default)
 * and EU clusters.
 */
final readonly class SinchConfig
{
    public function __construct(
        public string $servicePlanId,
        public string $apiToken,
        public ?string $from = null,
        public ?SinchRegion $region = null,
    ) {}
}
