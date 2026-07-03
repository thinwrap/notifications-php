<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Resend;

/**
 * Resend connector configuration.
 *
 * Bearer auth — `$apiKey` is the opaque token. `$senderName` composes into
 * "Name <email>" wire format when set; otherwise the bare `$from` address is
 * used. No token-cache hook per the stateless-wrapper rule
 * — Bearer is opaque-static.
 */
final readonly class ResendConfig
{
    public function __construct(
        public string $apiKey,
        public string $from,
        public ?string $senderName = null,
    ) {}
}
