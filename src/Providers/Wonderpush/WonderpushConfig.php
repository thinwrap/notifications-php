<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Wonderpush;

/**
 * Stub config for WonderPush push. Real shape lands in a later release.
 */
final readonly class WonderpushConfig
{
    public function __construct(
        public string $applicationId,
        public string $accessToken,
    ) {}
}
