<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Postmark;

use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

/**
 * Postmark-specific narrowed input. Documents Postmark-only fields routed via
 * `_passthrough` at v1.0 (`MessageStream`, `TemplateId`, `TemplateAlias`,
 * `TemplateModel`, `Metadata`, `TrackOpens`, `TrackLinks`). These are NOT first
 * -class fields per the ≥90%-of-providers baseline rule.
 *
 * @phpstan-type TemplateModel array<string,mixed>
 * @phpstan-type Metadata array<string,string>
 */
final readonly class PostmarkNarrowedInput extends EmailSendInput
{
    /**
     * @param list<string>|null $cc
     * @param list<string>|null $bcc
     * @param list<EmailAttachment>|null $attachments
     * @param array<string,string>|null $headers
     * @param list<string>|null $tags
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param TemplateModel|null $templateModel
     * @param Metadata|null $metadata
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
        public ?string $messageStream = null,
        public int|string|null $templateId = null,
        public ?string $templateAlias = null,
        public ?array $templateModel = null,
        public ?array $metadata = null,
        public ?bool $trackOpens = null,
        public ?string $trackLinks = null,
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
