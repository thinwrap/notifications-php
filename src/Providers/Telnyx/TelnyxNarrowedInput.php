<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Telnyx;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

/**
 * Telnyx-narrowed input. Below-baseline fields (under the ≥90%-of-providers threshold).
 */
final readonly class TelnyxNarrowedInput extends SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param list<string>|null $mediaUrls
     */
    public function __construct(
        string $to,
        string $body,
        ?string $from = null,
        ?array $_passthrough = null,
        public ?string $messagingProfileId = null,
        public ?string $webhookUrl = null,
        public ?string $webhookFailoverUrl = null,
        public ?bool $useProfileWebhooks = null,
        public ?string $type = null,
        public ?bool $autoDetect = null,
        public ?array $mediaUrls = null,
        public ?string $subject = null,
    ) {
        parent::__construct(to: $to, body: $body, from: $from, _passthrough: $_passthrough);
    }
}
