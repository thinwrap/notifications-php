<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\OneSignal;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for OneSignal push. Real shape lands in a later release.
 */
final readonly class OneSignalConfig
{
    use RedactsSecrets;

    public function __construct(
        public string $appId,
        #[\SensitiveParameter]
        public string $restApiKey,
    ) {}
}
