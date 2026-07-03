<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Textmagic;

/**
 * Textmagic SMS connector configuration.
 *
 * Auth is a TWO-HEADER PAIR — `X-TM-Username` + `X-TM-Key` are independent
 * headers, both required. Distinct from every other wrapped SMS provider's
 * single-header / Basic / Bearer scheme.
 */
final readonly class TextmagicConfig
{
    public function __construct(
        public string $username,
        public string $apiKey,
        public ?string $from = null,
    ) {}
}
