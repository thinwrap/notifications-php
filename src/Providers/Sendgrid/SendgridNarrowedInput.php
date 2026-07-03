<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sendgrid;

use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

/**
 * SendGrid-specific narrowed input. Captures fields below the ≥90% baseline
 * per the ≥90%-of-providers baseline rule.
 *
 * Templated email (`templateId` + `dynamicTemplateData`) is intentionally NOT
 * promoted to the baseline at v1.0 — accessible via `_passthrough.body.template_id`
 * + `_passthrough.body.personalizations[0].dynamic_template_data` until v1.1.
 *
 * @phpstan-type Asm array{group_id?:int,groups_to_display?:list<int>}
 * @phpstan-type MailSettings array<string,mixed>
 * @phpstan-type TrackingSettings array<string,mixed>
 */
final readonly class SendgridNarrowedInput extends EmailSendInput
{
    /**
     * @param list<string>|null $cc
     * @param list<string>|null $bcc
     * @param list<EmailAttachment>|null $attachments
     * @param array<string,string>|null $headers
     * @param list<string>|null $tags
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param array<string,mixed>|null $dynamicTemplateData
     * @param Asm|null $asm
     * @param MailSettings|null $mailSettings
     * @param TrackingSettings|null $trackingSettings
     * @param list<string>|null $categories
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
        public ?string $templateId = null,
        public ?array $dynamicTemplateData = null,
        public ?int $sendAt = null,
        public ?string $batchId = null,
        public ?array $asm = null,
        public ?array $mailSettings = null,
        public ?array $trackingSettings = null,
        public ?string $ipPoolName = null,
        public ?array $categories = null,
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
