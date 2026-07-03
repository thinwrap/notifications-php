<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mattermost;

/**
 * Stub config for Mattermost (webhook). Real shape lands in a later release.
 */
final readonly class MattermostConfig
{
    public function __construct(public string $webhookUrl) {}
}
