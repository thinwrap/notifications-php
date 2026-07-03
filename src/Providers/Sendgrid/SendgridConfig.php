<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sendgrid;

/**
 * SendGrid connector configuration. Single global endpoint at v1.0 — EU
 * sub-account routing is account-config, not URL-controlled.
 */
final readonly class SendgridConfig
{
    public function __construct(
        public string $apiKey,
        public string $from,
        public ?string $senderName = null,
    ) {}
}
