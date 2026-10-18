<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Telegram;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for Telegram Bot API. Real shape lands in a later release.
 */
final readonly class TelegramConfig
{
    use RedactsSecrets;

    public function __construct(#[\SensitiveParameter] public string $botToken) {}
}
