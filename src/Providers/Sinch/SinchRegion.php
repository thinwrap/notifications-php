<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sinch;

/**
 * Sinch regional cluster selector — `us` (default, canonical) vs `eu` (EU
 * data residency). The value is used as a subdomain prefix in
 * `https://<region>.sms.api.sinch.com`.
 */
enum SinchRegion: string
{
    case Us = 'us';
    case Eu = 'eu';
}
