<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Msteams;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for Microsoft Teams chat (webhook). Real shape lands in
 * a later release. Provider id is `msteams` (no hyphen) for cross-language alignment.
 */
final readonly class MsteamsConfig
{
    use RedactsSecrets;

    public function __construct(#[\SensitiveParameter] public string $webhookUrl) {}
}
