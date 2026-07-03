<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Email;

/**
 * Email send result status — string-backed enum mirroring TS's
 * `EmailSendResult.status` literal union (`'sent' | 'queued' | 'rejected' |
 * 'suppressed' | 'unknown'`). A backed enum so Email matches the SMS/Push/Chat
 * status constraint — no off-union literal can ship silently.
 *
 * The 2xx success literal is vendor-faithful per connector: a synchronous send
 * acknowledged on the wire maps to `Sent`; an async accept-for-delivery maps to
 * `Queued`. See each connector's docblock for its mapping.
 */
enum EmailStatus: string
{
    case Sent       = 'sent';
    case Queued     = 'queued';
    case Rejected   = 'rejected';
    case Suppressed = 'suppressed';
    case Unknown    = 'unknown';
}
