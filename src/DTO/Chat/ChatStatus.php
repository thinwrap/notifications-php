<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Chat;

/**
 * Chat send result status — mirrors TS `'sent' | 'queued' | 'rejected' |
 * 'suppressed' | 'unknown'` literal union. Wire values byte-identical
 * to TS for cross-language harmonization.
 */
enum ChatStatus: string
{
    case Sent       = 'sent';
    case Queued     = 'queued';
    case Rejected   = 'rejected';
    case Suppressed = 'suppressed';
    case Unknown    = 'unknown';
}
