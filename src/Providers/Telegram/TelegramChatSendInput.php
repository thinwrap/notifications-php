<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Telegram;

use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

/**
 * Telegram-narrowed `ChatSendInput`. Mirror of TS `TelegramNarrowedInput`.
 *
 * `to` is REQUIRED — Telegram has no notion of a "default chat" at the bot
 * level. Wire keys are snake_case (`parse_mode`, `disable_notification`, …)
 * spelled directly per casing-locality.
 */
final readonly class TelegramChatSendInput extends ChatSendInput
{
    /**
     * @param list<array<string,mixed>>|null $entities
     * @param array<string,mixed>|null $replyParameters
     * @param array<string,mixed>|null $replyMarkup
     * @param array<string,mixed>|null $linkPreviewOptions
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $body,
        public ?string $parseMode = null,
        public ?array $entities = null,
        public ?bool $disableNotification = null,
        public ?bool $protectContent = null,
        public ?array $replyParameters = null,
        public ?array $replyMarkup = null,
        public ?array $linkPreviewOptions = null,
        public ?int $messageThreadId = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct($to, $body, $_passthrough);
    }
}
