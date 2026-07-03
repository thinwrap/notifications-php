<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\OneSignal;

use Thinwrap\Notifications\DTO\Push\PushSendInput;

/**
 * OneSignal-narrowed `PushSendInput`. Mirror of TS `OneSignalNarrowedInput`.
 *
 * Recipient-routing precedence: when any of `include_external_user_ids`,
 * `include_player_ids`, `included_segments`, `excluded_segments` is set, the
 * base `to` is ignored and `include_subscription_ids` is omitted.
 *
 * `data` is widened to arbitrary JSON; OneSignal accepts arbitrary JSON.
 *
 * Field names are spelled with snake_case to match the wire shape verbatim
 * per the casing-locality rule.
 */
final readonly class OneSignalPushSendInput extends PushSendInput
{
    /**
     * @param array<string,mixed>|null $data
     * @param list<string>|null $include_external_user_ids
     * @param list<string>|null $include_player_ids
     * @param list<string>|null $included_segments
     * @param list<string>|null $excluded_segments
     * @param array<string,string>|null $headings   Localized title map, e.g. `['en' => 'Hi', 'es' => 'Hola']`.
     * @param array<string,string>|null $contents   Localized body map.
     * @param array<string,string>|null $ios_attachments
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to = '',
        string $title = '',
        string $body = '',
        ?array $data = null,
        public ?array $include_external_user_ids = null,
        public ?array $include_player_ids = null,
        public ?array $included_segments = null,
        public ?array $excluded_segments = null,
        public ?array $headings = null,
        public ?array $contents = null,
        public ?array $ios_attachments = null,
        public ?string $big_picture = null,
        public ?string $android_channel_id = null,
        public ?string $ios_sound = null,
        public ?string $android_sound = null,
        public ?string $send_after = null,
        public ?string $delayed_option = null,
        public ?int $priority = null,
        public ?int $ttl = null,
        public ?string $external_id = null,
        public ?string $collapse_id = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct($to, $title, $body, $data, $_passthrough);
    }
}
