<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Postmark;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Postmark connector configuration. `$serverToken` is sent via the idiosyncratic
 * `X-Postmark-Server-Token` header — NOT Bearer-style auth.
 */
final readonly class PostmarkConfig
{
    use RedactsSecrets;

    public function __construct(
        #[\SensitiveParameter]
        public string $serverToken,
        public string $from,
        public ?string $senderName = null,
        public ?string $messageStream = null,
    ) {}
}
