<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailtrap;

use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

/**
 * Mailtrap-specific narrowed input. Below-baseline fields (Mailtrap-only — well
 * below the ≥90%-of-providers threshold the normalized surface requires):
 * `category`, `customVariables`, `templateUuid`, `templateVariables`.
 *
 * Wire mapping: `category` → `category`, `customVariables` →
 * `custom_variables`, `templateUuid` → `template_uuid`, `templateVariables` →
 * `template_variables`. A template send drops `subject` / `text` / `html`
 * (Mailtrap's template schema has none) and rejects `category`.
 * `_passthrough.body` still wins on conflict.
 */
final readonly class MailtrapNarrowedInput extends EmailSendInput
{
    /**
     * @param list<string>|null $cc
     * @param list<string>|null $bcc
     * @param list<EmailAttachment>|null $attachments
     * @param array<string,string>|null $headers
     * @param list<string>|null $tags
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param array<string,string>|null $customVariables
     * @param array<string,mixed>|null $templateVariables
     */
    public function __construct(
        string $from,
        string $to,
        string $subject,
        ?array $cc = null,
        ?array $bcc = null,
        ?string $replyTo = null,
        ?string $text = null,
        ?string $html = null,
        ?array $attachments = null,
        ?array $headers = null,
        ?array $tags = null,
        ?array $_passthrough = null,
        public ?string $category = null,
        public ?array $customVariables = null,
        public ?string $templateUuid = null,
        public ?array $templateVariables = null,
    ) {
        parent::__construct(
            from: $from,
            to: $to,
            subject: $subject,
            cc: $cc,
            bcc: $bcc,
            replyTo: $replyTo,
            text: $text,
            html: $html,
            attachments: $attachments,
            headers: $headers,
            tags: $tags,
            _passthrough: $_passthrough,
        );
    }
}
