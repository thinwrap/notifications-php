<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Slack;

use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

/**
 * Slack-narrowed `ChatSendInput`. Mirror of TS `SlackNarrowedInput`.
 *
 * Webhook-URL-as-auth: `to` is intentionally `null` — the webhook
 * URL itself targets the channel.
 */
final readonly class SlackChatSendInput extends ChatSendInput
{
    /**
     * @param list<array<string,mixed>>|null $blocks      Block Kit blocks.
     * @param list<array<string,mixed>>|null $attachments Legacy attachments.
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $body,
        public ?array $blocks = null,
        public ?array $attachments = null,
        public ?string $username = null,
        public ?string $iconEmoji = null,
        public ?string $iconUrl = null,
        public ?string $threadTs = null,
        public ?bool $mrkdwn = null,
        public ?bool $unfurlLinks = null,
        public ?bool $unfurlMedia = null,
        public ?bool $linkNames = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct(null, $body, $_passthrough);
    }
}
