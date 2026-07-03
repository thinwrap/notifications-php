<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Email;

/**
 * Email send input — baseline shape mirroring TS `EmailSendInput`.
 *
 * NOT `final` — per-connector NarrowedInput classes (e.g. `SendgridNarrowedInput`)
 * extend this base.
 */
readonly class EmailSendInput
{
    /**
     * @param list<string>|null $cc
     * @param list<string>|null $bcc
     * @param list<EmailAttachment>|null $attachments
     * @param array<string,string>|null $headers
     * @param list<string>|null $tags
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $subject,
        public ?array $cc = null,
        public ?array $bcc = null,
        public ?string $replyTo = null,
        public ?string $text = null,
        public ?string $html = null,
        public ?array $attachments = null,
        public ?array $headers = null,
        public ?array $tags = null,
        public ?array $_passthrough = null,
    ) {}
}
