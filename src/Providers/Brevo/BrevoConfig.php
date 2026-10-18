<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Brevo;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Brevo connector configuration. Mirrors the TS connector
 * (`notifications-ts/src/providers/brevo/brevo.config.ts`).
 *
 * Brevo auths via a custom `api-key` header (not `Authorization: Bearer …`).
 * `$from` is required at the config level — Brevo's `sender.email` always
 * derives from config, with no per-call override at v1.0.
 */
final readonly class BrevoConfig
{
    use RedactsSecrets;

    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $from,
        public ?string $senderName = null,
    ) {}
}
