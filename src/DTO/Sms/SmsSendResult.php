<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Sms;

/**
 * Uniform SMS send result. `$status` is the `SmsStatus` backed enum (PHP-side
 * choice — keeps connector authors honest where vendor body-layer statuses are
 * richer than HTTP status alone). Wire values match TS verbatim.
 */
final readonly class SmsSendResult
{
    public function __construct(
        public bool $success,
        public SmsStatus $status,
        public ?string $providerMessageId,
        public mixed $raw,
    ) {}
}
