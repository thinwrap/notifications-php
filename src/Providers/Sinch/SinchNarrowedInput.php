<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sinch;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

/**
 * Sinch-narrowed input. Below-baseline fields (under the ≥90%-of-providers threshold).
 */
final readonly class SinchNarrowedInput extends SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param array<string,array<string,string>|string>|null $parameters
     */
    public function __construct(
        string $to,
        string $body,
        ?string $from = null,
        ?array $_passthrough = null,
        public ?string $type = null,
        public ?array $parameters = null,
        public ?string $deliveryReport = null,
        public ?string $sendAt = null,
        public ?string $expireAt = null,
        public ?string $callbackUrl = null,
        public ?string $clientReference = null,
        public ?bool $feedbackEnabled = null,
        public ?bool $flashMessage = null,
    ) {
        parent::__construct(to: $to, body: $body, from: $from, _passthrough: $_passthrough);
    }
}
