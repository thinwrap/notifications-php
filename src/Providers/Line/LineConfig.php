<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Line;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for LINE Messaging API. Real shape lands in a later release.
 */
final readonly class LineConfig
{
    use RedactsSecrets;

    public function __construct(#[\SensitiveParameter] public string $channelAccessToken) {}
}
