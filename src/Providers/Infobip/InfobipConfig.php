<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Infobip;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Infobip SMS connector configuration. Infobip is unique in v1.0 in requiring a
 * **per-account base URL** — Infobip provisions a customer-specific subdomain
 * when an account is created (e.g., `xyz123.api.infobip.com`).
 *
 * `$baseUrl` is REQUIRED. Do NOT include scheme (`https://`) or trailing slash;
 * the connector prepends `https://` and appends the API path.
 *
 * Auth uses the custom `Authorization: App <apiKey>` scheme (not Bearer, not
 * Basic).
 */
final readonly class InfobipConfig
{
    use RedactsSecrets;

    public function __construct(
        public string $baseUrl,
        #[\SensitiveParameter]
        public string $apiKey,
        public ?string $from = null,
    ) {}
}
