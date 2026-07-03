<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailgun;

/**
 * Mailgun connector configuration.
 *
 * `$region` selects the Mailgun cluster (US default vs EU). Basic auth — the
 * literal `api` username plus `$apiKey` password per Mailgun's documented
 * convention.
 */
final readonly class MailgunConfig
{
    public function __construct(
        public string $apiKey,
        public string $domain,
        public ?string $from = null,
        public ?string $senderName = null,
        public ?MailgunRegion $region = null,
    ) {}
}
