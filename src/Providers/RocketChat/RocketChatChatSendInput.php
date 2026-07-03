<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\RocketChat;

use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

/**
 * Rocket.Chat-narrowed `ChatSendInput`. PHP-side divergence vs the TS connector:
 * PHP uses Rocket.Chat REST API auth (`X-Auth-Token` + `X-User-Id` against
 * `/api/v1/chat.postMessage`), so `to` IS required (carries the channel
 * identifier — `#channelName`, `@username`, or raw room id).
 *
 * Attachments pass through verbatim — Rocket.Chat already accepts the
 * Slack-compat snake_case shape; consumers supply snake_case directly per
 * casing-locality (matches Mattermost, differs from TS which transforms inside
 * `toWireAttachment`).
 */
final readonly class RocketChatChatSendInput extends ChatSendInput
{
    /**
     * @param list<array<string,mixed>>|null $attachments Slack-compat shape; snake_case keys verbatim.
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $body,
        public ?string $alias = null,
        public ?string $emoji = null,
        public ?string $avatar = null,
        public ?array $attachments = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct($to, $body, $_passthrough);
    }
}
