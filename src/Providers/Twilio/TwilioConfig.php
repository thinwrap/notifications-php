<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Twilio;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Twilio SMS connector configuration. HTTP Basic auth — `accountSid` is the
 * username, `authToken` the password.
 *
 * `$region` is the processing region (us1 default, ie1, au1). `$edge`
 * overrides where the request enters Twilio's network; without it ie1 uses
 * `dublin`, au1 `sydney`, and us1 the plain `api.twilio.com`.
 */
final readonly class TwilioConfig
{
    use RedactsSecrets;

    public function __construct(
        public string $accountSid,
        #[\SensitiveParameter]
        public string $authToken,
        public ?string $from = null,
        public ?TwilioRegion $region = null,
        public ?TwilioEdge $edge = null,
    ) {}
}
