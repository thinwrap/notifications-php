<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Discord;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for Discord chat (webhook). Real shape lands in a later release.
 */
final readonly class DiscordConfig
{
    use RedactsSecrets;

    public function __construct(#[\SensitiveParameter] public string $webhookUrl) {}
}
