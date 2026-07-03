<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Messagebird;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

/**
 * MessageBird-narrowed input. Below-baseline fields (under the ≥90%-of-providers threshold). casing-locality outlier: `dataCoding` is
 * mapped to the lowercased-flat wire key `datacoding` by the connector.
 */
final readonly class MessagebirdNarrowedInput extends SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param array<string,mixed>|null $typeDetails
     */
    public function __construct(
        string $to,
        string $body,
        ?string $from = null,
        ?array $_passthrough = null,
        public ?string $type = null,
        public ?string $reference = null,
        public ?int $validity = null,
        public ?string $gateway = null,
        public ?array $typeDetails = null,
        public ?string $dataCoding = null,
        public ?int $mclass = null,
        public ?string $scheduledDatetime = null,
    ) {
        parent::__construct(to: $to, body: $body, from: $from, _passthrough: $_passthrough);
    }
}
