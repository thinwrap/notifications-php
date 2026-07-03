<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Apns;

use Thinwrap\Notifications\DTO\Push\PushSendInput;

/**
 * APNs-narrowed `PushSendInput`. Mirror of TS `ApnsPushSendInput`.
 *
 * APNs widens `data` to arbitrary JSON (root-level custom keys per Apple's spec).
 * The `aps` block spells Apple's kebab-case keys verbatim (`thread-id`,
 * `content-available`, …) — no casing transform is applied; consumers populate
 * the wire-spec keys directly.
 */
final readonly class ApnsPushSendInput extends PushSendInput
{
    /**
     * @param array<string,mixed>|null $data        Root-level custom keys merged into APNs payload alongside `aps`.
     * @param array<string,mixed>|null $aps         Apple-spec `aps` dictionary (kebab-case keys).
     * @param int|null $badge
     * @param string|null $sound
     * @param int|null $ttl                          Computes `apns-expiration` header (epoch seconds = now + ttl).
     * @param string|null $apnsTopic                Overrides `config.bundleId` in the `apns-topic` header.
     * @param string|null $apnsPushType             Defaults to `'alert'` when title/body present, else `'background'`.
     * @param int|null $apnsPriority                10 = immediate; 5 = considerate.
     * @param string|null $apnsCollapseId
     * @param int|null $apnsExpiration              Explicit unix-timestamp; otherwise computed from ttl.
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $title = '',
        string $body = '',
        ?array $data = null,
        public ?array $aps = null,
        public ?int $badge = null,
        public ?string $sound = null,
        public ?int $ttl = null,
        public ?string $apnsTopic = null,
        public ?string $apnsPushType = null,
        public ?int $apnsPriority = null,
        public ?string $apnsCollapseId = null,
        public ?int $apnsExpiration = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct($to, $title, $body, $data, $_passthrough);
    }
}
