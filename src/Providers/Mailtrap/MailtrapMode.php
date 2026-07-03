<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailtrap;

/**
 * Mailtrap dispatch mode — `Sandbox` routes to `sandbox.api.mailtrap.io`
 * (captures in inbox UI; no real delivery); `Production` routes to
 * `send.api.mailtrap.io` (actually delivers).
 *
 * String-backed enum so the wire / env-config layer can round-trip.
 */
enum MailtrapMode: string
{
    case Sandbox    = 'sandbox';
    case Production = 'production';
}
