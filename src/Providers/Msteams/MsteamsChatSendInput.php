<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Msteams;

use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

/**
 * MS Teams-narrowed `ChatSendInput`. Mirror of TS `MsTeamsNarrowedInput`.
 *
 * Webhook-URL-as-auth: `to` is intentionally `null`. When `card` is
 * set, it replaces the synthesized body-as-text Adaptive Card.
 */
final readonly class MsteamsChatSendInput extends ChatSendInput
{
    /**
     * @param array<string,mixed>|null $card     Full Adaptive Card payload.
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $body,
        public ?array $card = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct(null, $body, $_passthrough);
    }
}
