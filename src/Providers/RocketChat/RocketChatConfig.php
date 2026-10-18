<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\RocketChat;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for Rocket.Chat. Real shape lands in a later release.
 */
final readonly class RocketChatConfig
{
    use RedactsSecrets;

    public function __construct(
        public string $baseUrl,
        public string $userId,
        #[\SensitiveParameter]
        public string $authToken,
    ) {}
}
