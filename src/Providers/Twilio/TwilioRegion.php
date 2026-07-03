<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Twilio;

/**
 * Twilio regional cluster selector. `Us1` is the canonical default and uses
 * `api.twilio.com` (no regional subdomain); every other case uses
 * `api.<region>.twilio.com`.
 */
enum TwilioRegion: string
{
    case Us1 = 'us1';
    case Us2 = 'us2';
    case Ie1 = 'ie1';
    case Au1 = 'au1';
    case Br1 = 'br1';
    case De1 = 'de1';
    case Jp1 = 'jp1';
    case Sg1 = 'sg1';
}
