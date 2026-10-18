<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Wonderpush;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for WonderPush push. Real shape lands in a later release.
 */
final readonly class WonderpushConfig
{
    use RedactsSecrets;

    public function __construct(
        public string $applicationId,
        #[\SensitiveParameter]
        public string $accessToken,
    ) {}
}
