<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Infobip;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

/**
 * Infobip-narrowed input. Below-baseline fields (under the ≥90%-of-providers threshold). `$scheduleSettings` allows
 * `['sendAt' => 'ISO-8601']`.
 */
final readonly class InfobipNarrowedInput extends SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param array{sendAt?:string}|null $scheduleSettings
     */
    public function __construct(
        string $to,
        string $body,
        ?string $from = null,
        ?array $_passthrough = null,
        public ?string $callbackData = null,
        public ?string $notifyUrl = null,
        public ?string $notifyContentType = null,
        public ?int $validityPeriod = null,
        public ?string $validityPeriodTimeUnit = null,
        public ?bool $flash = null,
        public ?string $language = null,
        public ?string $transliteration = null,
        public ?string $bulkId = null,
        public ?array $scheduleSettings = null,
    ) {
        parent::__construct(to: $to, body: $body, from: $from, _passthrough: $_passthrough);
    }
}
