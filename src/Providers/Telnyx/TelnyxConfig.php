<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Telnyx;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Telnyx SMS connector configuration. Bearer auth — `$apiKey` is sent as
 * `Authorization: Bearer <apiKey>`.
 */
final readonly class TelnyxConfig
{
    use RedactsSecrets;

    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public ?string $from = null,
    ) {}
}
