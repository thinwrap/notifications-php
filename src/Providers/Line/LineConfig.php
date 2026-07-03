<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Line;

/**
 * Stub config for LINE Messaging API. Real shape lands in a later release.
 */
final readonly class LineConfig
{
    public function __construct(public string $channelAccessToken) {}
}
