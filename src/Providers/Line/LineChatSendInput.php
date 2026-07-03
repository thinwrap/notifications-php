<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Line;

use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

/**
 * LINE-narrowed `ChatSendInput`. Mirror of TS `LineChatInput`.
 *
 * LINE's distinguishing wire feature is `messages: LineMessage[]` (array, not
 * a single message). When `$messages === null`, the connector auto-synthesizes
 * a single text message from `$body`. When `$messages` is set, it is used
 * directly and `$body` is ignored (parallel to WhatsApp Business
 * template/interactive override semantics).
 *
 * `messages` is typed loosely as `array<int,array<string,mixed>>` — LINE's
 * Flex / sticker / template variants form an 8-way discriminated union TS
 * models natively but PHP does not narrow ergonomically. Consumers wanting
 * strict typing read the TS counterpart for the variant catalog.
 */
final readonly class LineChatSendInput extends ChatSendInput
{
    /**
     * @param list<array<string,mixed>>|null $messages 1–5 LINE message objects (text/flex/sticker/template/image/video/audio/location).
     * @param list<array<string,mixed>>|null $customAggregationUnits
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $body = '',
        public ?array $messages = null,
        public ?bool $notificationDisabled = null,
        public ?array $customAggregationUnits = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct($to, $body, $_passthrough);
    }
}
