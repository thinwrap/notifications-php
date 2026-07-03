<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Sms;

/**
 * SMS send result status — string-backed enum mirroring TS's `SmsSendResult.status`
 * literal union (`'sent' | 'queued' | 'rejected' | 'suppressed' | 'unknown'`)
 * Cross-language harmonization is verified by diffing the wire `value` strings
 * against TS.
 */
enum SmsStatus: string
{
    case Sent       = 'sent';
    case Queued     = 'queued';
    case Rejected   = 'rejected';
    case Suppressed = 'suppressed';
    case Unknown    = 'unknown';
}
