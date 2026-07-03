<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Plivo;

/**
 * Plivo SMS connector configuration. HTTP Basic auth — `authId` (also the URL
 * path segment) is the username, `authToken` the password.
 */
final readonly class PlivoConfig
{
    public function __construct(
        public string $authId,
        public string $authToken,
        public ?string $from = null,
    ) {}
}
