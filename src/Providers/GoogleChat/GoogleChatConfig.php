<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\GoogleChat;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for Google Chat (webhook). Real shape lands in a later release.
 */
final readonly class GoogleChatConfig
{
    use RedactsSecrets;

    public function __construct(#[\SensitiveParameter] public string $webhookUrl) {}
}
