<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sparkpost;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * SparkPost connector configuration. Mirrors the TS connector
 * (`notifications-ts/src/providers/sparkpost/sparkpost.config.ts`).
 *
 * SparkPost auths with the API key sent VERBATIM in the `Authorization` header
 * (no `Bearer` prefix). `$region` selects between the US (default) and EU
 * clusters.
 */
final readonly class SparkpostConfig
{
    use RedactsSecrets;

    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $from,
        public ?string $senderName = null,
        public ?SparkpostRegion $region = null,
    ) {}
}
