<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Plivo;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Plivo SMS connector configuration. HTTP Basic auth — `authId` (also the URL
 * path segment) is the username, `authToken` the password.
 */
final readonly class PlivoConfig
{
    use RedactsSecrets;

    public function __construct(
        public string $authId,
        #[\SensitiveParameter]
        public string $authToken,
        public ?string $from = null,
    ) {}
}
