<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Telegram;

/**
 * Stub config for Telegram Bot API. Real shape lands in a later release.
 */
final readonly class TelegramConfig
{
    public function __construct(public string $botToken) {}
}
