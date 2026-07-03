<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Email;

/**
 * Uniform email send result. `$status` is an {@see EmailStatus} backed enum
 * (one of sent | queued | rejected | suppressed | unknown) matching TS's
 * literal union for cross-language harmonization. The enum brings Email to
 * parity with the SMS/Push/Chat status enums and prevents off-union literals
 * from shipping silently.
 */
final readonly class EmailSendResult
{
    public function __construct(
        public bool $success,
        public EmailStatus $status,
        public ?string $providerMessageId,
        public mixed $raw,
    ) {}
}
