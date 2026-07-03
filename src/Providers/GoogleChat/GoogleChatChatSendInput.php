<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\GoogleChat;

use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

/**
 * Google Chat-narrowed `ChatSendInput`. Mirror of TS `GoogleChatNarrowedInput`.
 *
 * Webhook-URL-as-auth: `to` is intentionally `null` — the webhook
 * URL targets the space. Wire keys are camelCase per Google's API style guide.
 */
final readonly class GoogleChatChatSendInput extends ChatSendInput
{
    /**
     * @param list<array<string,mixed>>|null $cardsV2  Google's canonical Cards v2 format.
     * @param array{name:string}|null $thread         Wire key `thread.name` for threading.
     * @param string|null $fallbackText               Accessibility fallback when only cards present.
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $body,
        public ?array $cardsV2 = null,
        public ?array $thread = null,
        public ?string $fallbackText = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct(null, $body, $_passthrough);
    }
}
