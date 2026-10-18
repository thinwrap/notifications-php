<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sinch;

/**
 * Sinch SMS regional cluster — `us` (default), `eu`, `au`, `br`, `ca`, the
 * regions the Sinch SMS API reference lists. The value is used as a subdomain
 * prefix in `https://<region>.sms.api.sinch.com`; a service plan belongs to
 * one region.
 */
enum SinchRegion: string
{
    case Us = 'us';
    case Eu = 'eu';
    case Au = 'au';
    case Br = 'br';
    case Ca = 'ca';
}
