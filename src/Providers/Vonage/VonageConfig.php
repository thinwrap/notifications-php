<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Vonage;

/**
 * Vonage (formerly Nexmo) SMS connector configuration.
 *
 * `apiKey` + `apiSecret` are sent as FORM FIELDS in the POST body — Vonage is
 * one of the few wrapped SMS providers that auths via form fields instead of
 * HTTP headers. `$from` is optional at config level; can be supplied per-call.
 *
 * The Novu `nexmo` alias is TS-only; PHP has no `Nexmo` provider id,
 * so this config backs `VonageSmsConnector` under the single `vonage` id.
 */
final readonly class VonageConfig
{
    public function __construct(
        public string $apiKey,
        public string $apiSecret,
        public ?string $from = null,
    ) {}
}
