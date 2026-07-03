<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Contract;

use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatSendResult;

/**
 * Shared interface every chat connector implements. Mirrors TS `IChatConnector`.
 */
interface ChatConnectorInterface
{
    public function send(ChatSendInput $input): ChatSendResult;
}
