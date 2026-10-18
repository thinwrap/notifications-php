<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailersend;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * MailerSend connector configuration. Bearer auth — `$apiToken` is the
 * MailerSend API token. `$senderName` populates the wire `from.name` field
 * when provided.
 */
final readonly class MailersendConfig
{
    use RedactsSecrets;

    public function __construct(
        #[\SensitiveParameter]
        public string $apiToken,
        public ?string $senderName = null,
    ) {}
}
