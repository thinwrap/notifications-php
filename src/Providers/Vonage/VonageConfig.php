<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Vonage;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Vonage (formerly Nexmo) SMS connector configuration.
 *
 * `apiKey` + `apiSecret` are sent as FORM FIELDS in the POST body — Vonage is
 * one of the few wrapped SMS providers that auths via form fields instead of
 * HTTP headers. `$from` is optional at config level; can be supplied per-call.
 *
 * There is no `nexmo` provider id; this config backs `VonageSmsConnector` under
 * the single canonical `vonage` id.
 */
final readonly class VonageConfig
{
    use RedactsSecrets;

    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        #[\SensitiveParameter]
        public string $apiSecret,
        public ?string $from = null,
    ) {}
}
