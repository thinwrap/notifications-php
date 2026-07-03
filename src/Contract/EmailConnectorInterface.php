<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Contract;

use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailSendResult;

interface EmailConnectorInterface
{
    public function send(EmailSendInput $input): EmailSendResult;
}
