<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Textmagic;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

/**
 * Textmagic-narrowed input. Below-baseline fields (under the ≥90%-of-providers threshold).
 */
final readonly class TextmagicNarrowedInput extends SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $body,
        ?string $from = null,
        ?array $_passthrough = null,
        public ?int $templateId = null,
        public ?int $sendingTime = null,
        public ?string $tz = null,
        public ?int $partsCount = null,
        public ?int $referenceId = null,
        public ?string $rrule = null,
        public ?bool $cutExtra = null,
    ) {
        parent::__construct(to: $to, body: $body, from: $from, _passthrough: $_passthrough);
    }
}
