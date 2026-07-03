<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Contract;

use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushSendResult;

/**
 * Shared interface every push connector implements. Mirrors TS `IPushConnector`.
 */
interface PushConnectorInterface
{
    public function send(PushSendInput $input): PushSendResult;
}
