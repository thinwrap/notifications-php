<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\D7networks;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

/**
 * D7 Networks narrowed input. Below-baseline fields (D7-only — well below the
 * ≥90%-of-providers threshold the normalized surface requires): `tag`,
 * `dataCoding`, `scheduleTime`, `validityPeriod`, `originator`, `reportUrl`,
 * `msgType`.
 *
 * Wire keys are snake_case (`data_coding`, `schedule_time`, `validity_period`,
 * `report_url`, `msg_type`) — the connector body-builder is the single
 * translation point (casing locality).
 */
final readonly class D7networksNarrowedInput extends SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $body,
        ?string $from = null,
        ?array $_passthrough = null,
        public ?string $tag = null,
        public ?string $dataCoding = null,
        public ?string $scheduleTime = null,
        public ?int $validityPeriod = null,
        public ?string $originator = null,
        public ?string $reportUrl = null,
        public ?string $msgType = null,
    ) {
        parent::__construct(
            to: $to,
            body: $body,
            from: $from,
            _passthrough: $_passthrough,
        );
    }
}
