<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sendgrid;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * SendGrid connector configuration. Single global endpoint at v1.0 — EU
 * sub-account routing is account-config, not URL-controlled.
 */
final readonly class SendgridConfig
{
    use RedactsSecrets;

    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $from,
        public ?string $senderName = null,
    ) {}
}
