<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Telnyx;

/**
 * Telnyx SMS connector configuration. Bearer auth — `$apiKey` is sent as
 * `Authorization: Bearer <apiKey>`.
 */
final readonly class TelnyxConfig
{
    public function __construct(
        public string $apiKey,
        public ?string $from = null,
    ) {}
}
