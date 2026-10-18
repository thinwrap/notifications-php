<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Scaleway;

/**
 * Scaleway Transactional Email region. The selected value is interpolated into
 * the endpoint PATH (`/regions/{region}/emails`), not a host swap. Mirrors TS
 * `ScalewayRegion`.
 *
 * Scaleway currently serves Transactional Email only from `fr-par`
 * (scaleway-sdk-go tem/v1alpha1 `Regions()`); the other regions are passed
 * through as given, so a send there fails at Scaleway if it is not offered.
 */
enum ScalewayRegion: string
{
    case FrPar = 'fr-par';
    case NlAms = 'nl-ams';
    case PlWaw = 'pl-waw';
}
