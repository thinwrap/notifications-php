<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\PusherBeams;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Configuration for the Pusher Beams push connector: the `instanceId` and the
 * `secretKey` (sent as `Authorization: Bearer <secretKey>`), both from the
 * Pusher Beams dashboard.
 */
final readonly class PusherBeamsConfig
{
    use RedactsSecrets;

    public function __construct(
        public string $instanceId,
        #[\SensitiveParameter]
        public string $secretKey,
    ) {}
}
