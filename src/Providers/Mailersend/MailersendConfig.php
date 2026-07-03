<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailersend;

/**
 * MailerSend connector configuration. Bearer auth — `$apiToken` is the
 * MailerSend API token. `$senderName` populates the wire `from.name` field
 * when provided.
 */
final readonly class MailersendConfig
{
    public function __construct(
        public string $apiToken,
        public ?string $senderName = null,
    ) {}
}
