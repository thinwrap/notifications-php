<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\GoogleChat;

/**
 * Stub config for Google Chat (webhook). Real shape lands in a later release.
 */
final readonly class GoogleChatConfig
{
    public function __construct(public string $webhookUrl) {}
}
