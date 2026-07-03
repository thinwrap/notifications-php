<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Push;

/**
 * Push send input — 4-field baseline (`to`, `title`, `body`, `data`) mirroring
 * TS `PushSendInput`. The baseline is the ≥90% intersection of the 6 push
 * providers; per-connector NarrowedInput classes extend with vendor-specific
 * fields like `badge`, `sound`, `priority`, `ttl`, etc.
 *
 * `$title` and `$body` are nullable-with-default `null` to match TS's optional
 * `title?`/`body?` — this enables data-only / silent push (a real FCM/APNs/Expo
 * feature).
 *
 * `$data` is typed `array<string,string>` (string-only values, matching TS's
 * `Record<string,string>`); consumers needing nested objects route them via
 * `_passthrough`.
 *
 * NOT `final` — narrowed inputs (e.g. `FcmNarrowedInput`, `ApnsNarrowedInput`)
 * extend this base.
 */
readonly class PushSendInput
{
    /**
     * @param array<string,string>|null $data
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        public string $to,
        public ?string $title = null,
        public ?string $body = null,
        public ?array $data = null,
        public ?array $_passthrough = null,
    ) {}
}
