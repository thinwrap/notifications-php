<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\OneSignal;

/**
 * Stub config for OneSignal push. Real shape lands in a later release.
 */
final readonly class OneSignalConfig
{
    public function __construct(
        public string $appId,
        public string $restApiKey,
    ) {}
}
