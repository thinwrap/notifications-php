<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sparkpost;

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
    public function __construct(
        public string $apiKey,
        public string $from,
        public ?string $senderName = null,
        public ?SparkpostRegion $region = null,
    ) {}
}
