<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Twilio;

/**
 * Twilio SMS connector configuration. HTTP Basic auth — `accountSid` is the
 * username, `authToken` the password. `$region` selects the regional cluster
 * (us1 = canonical / default).
 */
final readonly class TwilioConfig
{
    public function __construct(
        public string $accountSid,
        public string $authToken,
        public ?string $from = null,
        public ?TwilioRegion $region = null,
    ) {}
}
