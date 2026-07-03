<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\PusherBeams;

use Thinwrap\Notifications\DTO\Push\PushSendInput;

/**
 * Pusher Beams-narrowed `PushSendInput`. Mirror of TS `PusherBeamsPushSendInput`.
 *
 * **casing-locality outlier:** Beams synthesizes BOTH an FCM payload AND an APNs payload
 * (and optionally a Web push payload) in a single request. The connector
 * builds these from the base `PushSendInput`; augmentation fields below
 * shallow-merge into the synthesized payloads.
 *
 * Routing precedence: `interests` > `users` > `[to]`.
 */
final readonly class PusherBeamsPushSendInput extends PushSendInput
{
    /**
     * @param array<string,mixed>|null $data
     * @param list<string>|null $users
     * @param list<string>|null $interests
     * @param array<string,mixed>|null $fcm
     * @param array<string,mixed>|null $apns
     * @param array<string,mixed>|null $web
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to = '',
        string $title = '',
        string $body = '',
        ?array $data = null,
        public ?int $badge = null,
        public ?string $sound = null,
        public ?int $ttl = null,
        public ?array $users = null,
        public ?array $interests = null,
        public ?array $fcm = null,
        public ?array $apns = null,
        public ?array $web = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct($to, $title, $body, $data, $_passthrough);
    }
}
