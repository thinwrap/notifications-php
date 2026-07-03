<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Postmark;

/**
 * Postmark connector configuration. `$serverToken` is sent via the idiosyncratic
 * `X-Postmark-Server-Token` header — NOT Bearer-style auth.
 */
final readonly class PostmarkConfig
{
    public function __construct(
        public string $serverToken,
        public string $from,
        public ?string $senderName = null,
        public ?string $messageStream = null,
    ) {}
}
