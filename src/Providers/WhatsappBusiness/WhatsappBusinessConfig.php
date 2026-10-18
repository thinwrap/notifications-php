<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\WhatsappBusiness;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * WhatsApp Business Cloud API connector config. Mirrors TS
 * `WhatsAppBusinessConfig`. `graphApiVersion` pins the Meta Graph API segment
 * embedded in the URL (`/v21.0/...`) — bumping requires a connector code review
 * since template/interactive payloads occasionally rev shape across versions.
 */
final readonly class WhatsappBusinessConfig
{
    use RedactsSecrets;

    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        public string $phoneNumberId,
        public string $graphApiVersion = 'v21.0',
    ) {}
}
