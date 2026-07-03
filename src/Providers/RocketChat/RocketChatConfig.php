<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\RocketChat;

/**
 * Stub config for Rocket.Chat. Real shape lands in a later release.
 */
final readonly class RocketChatConfig
{
    public function __construct(
        public string $baseUrl,
        public string $userId,
        public string $authToken,
    ) {}
}
