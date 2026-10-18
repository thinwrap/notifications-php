<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Twilio;

/**
 * Twilio edge location — where a request enters Twilio's network, as opposed
 * to the processing region (`TwilioRegion`). The closed list of public edges
 * from Twilio's edge-locations page; the value becomes a host label
 * (`api.{edge}.{region}.twilio.com`).
 */
enum TwilioEdge: string
{
    case Sydney = 'sydney';
    case SaoPaulo = 'sao-paulo';
    case Dublin = 'dublin';
    case Frankfurt = 'frankfurt';
    case Tokyo = 'tokyo';
    case Singapore = 'singapore';
    case Ashburn = 'ashburn';
    case Umatilla = 'umatilla';
    case Roaming = 'roaming';
}
