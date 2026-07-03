<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Fcm;

use Thinwrap\Notifications\DTO\Push\PushSendInput;

/**
 * FCM-narrowed `PushSendInput`. Surface mirror of TS `FcmPushSendInput`
 * (notifications-ts/src/providers/fcm/fcm.types.ts).
 *
 * Platform blocks (`android` / `apns` / `webpush` / `fcm_options`) are
 * minimally-typed `array<string,mixed>` so consumers can populate documented
 * FCM HTTP v1 fields directly; rarely-used fields flow through
 * `_passthrough.body.message.*` per the baseline-coverage discipline.
 *
 * NOTE: `apns` here is the FCM-side APNs payload nested inside an FCM
 * message — it is NOT a reference to the `ApnsPushConnector`.
 */
final readonly class FcmPushSendInput extends PushSendInput
{
    /**
     * @param array<string,string>|null $data
     * @param array<string,mixed>|null $android      Optional FCM `android` block.
     * @param array<string,mixed>|null $apns         Optional FCM-side APNs payload (nested inside FCM message).
     * @param array<string,mixed>|null $webpush     Optional FCM `webpush` block.
     * @param array<string,mixed>|null $fcm_options Optional FCM `fcm_options` block (`analytics_label`, etc.).
     * @param int|null $ttl                          Time-to-live in seconds; emitted as `android.ttl = "<seconds>s"`.
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $title = '',
        string $body = '',
        ?array $data = null,
        public ?int $ttl = null,
        public ?array $android = null,
        public ?array $apns = null,
        public ?array $webpush = null,
        public ?array $fcm_options = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct($to, $title, $body, $data, $_passthrough);
    }
}
