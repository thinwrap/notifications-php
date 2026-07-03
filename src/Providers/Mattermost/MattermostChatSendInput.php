<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mattermost;

use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

/**
 * Mattermost-narrowed `ChatSendInput`. Mirror of TS `MattermostNarrowedInput`.
 *
 * Webhook-URL-as-auth: `to` is intentionally `null` — the webhook URL
 * targets the (default) channel. Server-config gating: `username`, `iconUrl`,
 * `iconEmoji`, `channel` are silently ignored unless the server admin enabled
 * "Enable integrations to override usernames / icons / channels".
 */
final readonly class MattermostChatSendInput extends ChatSendInput
{
    /**
     * @param list<SlackCompatAttachment>|null $attachments
     * @param array<string,mixed>|null $props
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $body,
        public ?array $attachments = null,
        public ?string $username = null,
        public ?string $iconUrl = null,
        public ?string $iconEmoji = null,
        public ?string $channel = null,
        public ?array $props = null,
        public ?string $type = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct(null, $body, $_passthrough);
    }
}
