<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Wonderpush;

use Thinwrap\Notifications\DTO\Push\PushSendInput;

/**
 * WonderPush-narrowed `PushSendInput`. Mirror of TS `WonderPushNarrowedInput`.
 *
 * Recipient routing:
 *   - default `targetUserIds = [input->to]`.
 *   - `targetUserIds` augmentation overrides the default.
 *   - `targetSegmentIds` flows through verbatim and may coexist.
 */
final readonly class WonderpushPushSendInput extends PushSendInput
{
    /**
     * @param array<string,mixed>|null $data
     * @param list<string>|null $targetUserIds
     * @param list<string>|null $targetSegmentIds
     * @param array<string,mixed>|null $customSegmentation
     * @param array<string,mixed>|null $notification     Full `notification` block override.
     * @param list<array<string,mixed>>|null $actions
     * @param list<string>|null $categories
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $title = '',
        string $body = '',
        ?array $data = null,
        public ?int $badge = null,
        public ?string $sound = null,
        public ?string $subtitle = null,
        public ?array $targetUserIds = null,
        public ?array $targetSegmentIds = null,
        public ?array $customSegmentation = null,
        public ?array $notification = null,
        public ?array $actions = null,
        public ?array $categories = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct($to, $title, $body, $data, $_passthrough);
    }
}
