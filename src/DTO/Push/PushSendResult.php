<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Push;

/**
 * Uniform push send result, structurally identical to `SmsSendResult` /
 * `EmailSendResult` / `ChatSendResult`.
 */
final readonly class PushSendResult
{
    public function __construct(
        public bool $success,
        public PushStatus $status,
        public ?string $providerMessageId,
        public mixed $raw,
    ) {}
}
