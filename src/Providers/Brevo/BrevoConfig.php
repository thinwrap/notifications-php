<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Brevo;

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
    public function __construct(
        public string $apiKey,
        public string $from,
        public ?string $senderName = null,
    ) {}
}
