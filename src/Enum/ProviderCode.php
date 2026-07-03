<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Enum;

/**
 * Canonical normalized error categories for notification connectors.
 *
 * String backing values match the TS `ProviderCode` union literals exactly
 * (cross-language parity). Exactly 6 cases — additions require a
 * coordinated TS + PHP change.
 */
enum ProviderCode: string
{
    case InvalidRecipient    = 'invalid_recipient';
    case RateLimited         = 'rate_limited';
    case AuthFailed          = 'auth_failed';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidRequest      = 'invalid_request';
    case Unknown             = 'unknown';
}
