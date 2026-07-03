<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Expo;

use Thinwrap\Notifications\DTO\Push\PushSendInput;

/**
 * Expo-narrowed `PushSendInput`. Mirror of TS `ExpoNarrowedInput`.
 *
 * Captures the Expo-only push-API parameters that are below the 90%
 * baseline-coverage threshold per the ≥90%-of-providers baseline rule;
 * consumers needing further fields flow them through `_passthrough.body`.
 */
final readonly class ExpoPushSendInput extends PushSendInput
{
    /**
     * @param array<string,mixed>|null $data
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $title = '',
        string $body = '',
        ?array $data = null,
        public ?string $sound = null,
        public ?int $badge = null,
        public ?int $ttl = null,
        public ?string $priority = null,
        public ?string $channelId = null,
        public ?string $categoryId = null,
        public ?bool $mutableContent = null,
        public ?string $subtitle = null,
        public ?string $interruptionLevel = null,
        public ?bool $displayInForeground = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct($to, $title, $body, $data, $_passthrough);
    }
}
