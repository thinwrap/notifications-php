<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Chat;

/**
 * Chat send input — baseline 2-field shape: the normalized facade surface
 * carries only fields supported by ≥90% of providers; everything else flows
 * through `_passthrough`. Mirrors TS `ChatSendInput`.
 *
 * Chat is the only channel where the baseline recipient is nullable. 5 of 9
 * chat providers (Slack, Discord, MS Teams, Mattermost,
 * Google Chat) use the webhook URL itself as both auth and channel routing —
 * no per-call `to` field exists in the wire request. The remaining 4
 * (Telegram, WhatsApp Business, Rocket.Chat, LINE) require `to`; those
 * connectors validate non-null in their own `send()` before HTTP. The facade
 * does NOT enforce `$to !== null` — null is constructively legal.
 *
 * NOT `final` — per-connector NarrowedInput classes extend this base.
 */
readonly class ChatSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        public ?string $to,
        public string $body,
        public ?array $_passthrough = null,
    ) {}
}
