<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Discord;

use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

/**
 * Discord-narrowed `ChatSendInput`. Mirror of TS `DiscordNarrowedInput`.
 *
 * Webhook-URL-as-auth: `to` is intentionally `null` — the webhook
 * URL targets the channel.
 */
final readonly class DiscordChatSendInput extends ChatSendInput
{
    /**
     * @param list<array<string,mixed>>|null $embeds         Up to 10 rich embeds.
     * @param list<array<string,mixed>>|null $components     Message components (buttons, selects, text inputs).
     * @param array<string,mixed>|null $allowedMentions
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $body,
        public ?array $embeds = null,
        public ?array $components = null,
        public ?string $username = null,
        public ?string $avatarUrl = null,
        public ?bool $tts = null,
        public ?int $flags = null,
        public ?array $allowedMentions = null,
        public ?string $threadName = null,
        public ?string $threadId = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct(null, $body, $_passthrough);
    }
}
