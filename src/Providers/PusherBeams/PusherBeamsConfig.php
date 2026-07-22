<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\PusherBeams;

/**
 * Configuration for the Pusher Beams push connector: the `instanceId` and the
 * `secretKey` (sent as `Authorization: Bearer <secretKey>`), both from the
 * Pusher Beams dashboard.
 */
final readonly class PusherBeamsConfig
{
    public function __construct(
        public string $instanceId,
        public string $secretKey,
    ) {}
}
