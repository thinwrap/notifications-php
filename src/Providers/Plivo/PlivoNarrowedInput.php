<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Plivo;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

/**
 * Plivo-narrowed extension of `SmsSendInput`. India DLT compliance fields
 * (`dltEntityId`, `dltTemplateId`, `dltTemplateCategory`) are first-class —
 * burying compliance-mandatory inputs in `_passthrough` would hide them from
 * IntelliSense.
 *
 * Below-baseline non-DLT fields (under the ≥90%-of-providers threshold): `url`/`method` (delivery webhook),
 * `log`/`trackable` (tracking toggles), `powerpackUuid`/`templateId`.
 */
final readonly class PlivoNarrowedInput extends SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $body,
        ?string $from = null,
        ?array $_passthrough = null,
        public ?string $url = null,
        public ?string $method = null,
        public ?bool $log = null,
        public ?bool $trackable = null,
        public ?string $dltEntityId = null,
        public ?string $dltTemplateId = null,
        public ?string $dltTemplateCategory = null,
        public ?string $powerpackUuid = null,
        public ?string $templateId = null,
    ) {
        parent::__construct(to: $to, body: $body, from: $from, _passthrough: $_passthrough);
    }
}
