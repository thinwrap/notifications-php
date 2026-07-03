<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\PusherBeams;

/**
 * Stub config for Pusher Beams push. Real shape lands in a later release.
 */
final readonly class PusherBeamsConfig
{
    public function __construct(
        public string $instanceId,
        public string $secretKey,
    ) {}
}
