<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Contract;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsSendResult;

/**
 * Shared interface every SMS connector implements. Mirrors TS `ISmsConnector`.
 */
interface SmsConnectorInterface
{
    public function send(SmsSendInput $input): SmsSendResult;
}
