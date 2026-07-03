<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Vonage;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

/**
 * Vonage-narrowed extension of `SmsSendInput`. Below-baseline fields per locked
 * memory the ≥90%-of-providers baseline rule — Vonage-only kebab-case wire
 * keys (`client-ref`, `message-class`, `status-report-req`) mapped from PHP
 * camelCase narrowed-input properties.
 */
final readonly class VonageNarrowedInput extends SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $body,
        ?string $from = null,
        ?array $_passthrough = null,
        public ?string $clientRef = null,
        public ?int $messageClass = null,
        public ?string $type = null,
        public ?int $statusReportReq = null,
        public ?int $ttl = null,
        public ?string $callback = null,
    ) {
        parent::__construct(to: $to, body: $body, from: $from, _passthrough: $_passthrough);
    }
}
