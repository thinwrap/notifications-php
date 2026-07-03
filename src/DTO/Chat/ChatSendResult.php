<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Chat;

/**
 * Uniform chat send result, structurally identical to `SmsSendResult` /
 * `EmailSendResult` / `PushSendResult`.
 */
final readonly class ChatSendResult
{
    public function __construct(
        public bool $success,
        public ChatStatus $status,
        public ?string $providerMessageId,
        public mixed $raw,
    ) {}
}
