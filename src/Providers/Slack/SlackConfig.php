<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Slack;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for Slack chat. Real shape lands in a later release.
 *
 * Slack uses webhook-URL-as-auth by design — the URL is both the credential
 * and the channel routing.
 */
final readonly class SlackConfig
{
    use RedactsSecrets;

    public function __construct(#[\SensitiveParameter] public string $webhookUrl) {}
}
