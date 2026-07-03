<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailgun;

/**
 * Mailgun regional cluster selector — US (default) vs EU.
 *
 * Backed by string to match the wire convention and to keep `value` accessible
 * for URL construction in the connector.
 */
enum MailgunRegion: string
{
    case Us = 'us';
    case Eu = 'eu';
}
