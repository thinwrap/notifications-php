<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mattermost;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for Mattermost (webhook). Real shape lands in a later release.
 */
final readonly class MattermostConfig
{
    use RedactsSecrets;

    public function __construct(#[\SensitiveParameter] public string $webhookUrl) {}
}
