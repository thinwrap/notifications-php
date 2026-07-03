<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\WhatsappBusiness;

/**
 * WhatsApp Business Cloud API connector config. Mirrors TS
 * `WhatsAppBusinessConfig`. `graphApiVersion` pins the Meta Graph API segment
 * embedded in the URL (`/v21.0/...`) — bumping requires a connector code review
 * since template/interactive payloads occasionally rev shape across versions.
 */
final readonly class WhatsappBusinessConfig
{
    public function __construct(
        public string $accessToken,
        public string $phoneNumberId,
        public string $graphApiVersion = 'v21.0',
    ) {}
}
