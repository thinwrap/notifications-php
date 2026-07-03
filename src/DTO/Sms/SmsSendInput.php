<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Sms;

/**
 * SMS send input — baseline shape mirroring TS `SmsSendInput`.
 *
 * Three baseline fields (`to`, `body`, `from`) — the normalized surface carries
 * only fields supported by ≥90% of SMS providers. `from` is optional at the
 * base level because some providers fix the sender via configuration;
 * per-connector NarrowedInput classes re-declare `from` as required where the
 * provider demands it.
 *
 * NOT `final` — per-connector NarrowedInput classes (e.g.
 * `TwilioNarrowedInput`) extend this base.
 */
readonly class SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        public string $to,
        public string $body,
        public ?string $from = null,
        public ?array $_passthrough = null,
    ) {}
}
