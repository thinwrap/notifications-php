<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Discord;

/**
 * Stub config for Discord chat (webhook). Real shape lands in a later release.
 */
final readonly class DiscordConfig
{
    public function __construct(public string $webhookUrl) {}
}
