<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Scaleway;

/**
 * Scaleway Transactional Email region — physically EU-resident clusters. The
 * selected value is interpolated into the endpoint PATH
 * (`/regions/{region}/emails`), not a host swap. Mirrors TS `ScalewayRegion`.
 */
enum ScalewayRegion: string
{
    case FrPar = 'fr-par';
    case NlAms = 'nl-ams';
    case PlWaw = 'pl-waw';
}
