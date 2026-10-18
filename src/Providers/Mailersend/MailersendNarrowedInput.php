<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailersend;

use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

/**
 * MailerSend-specific narrowed input. Captures fields beyond the ≥90% baseline
 * per the ≥90%-of-providers baseline rule.
 *
 * Wire mapping: `templateId` → `template_id`, `personalization` →
 * `personalization`, `sendAt` (unix seconds) → `send_at`, `precedenceBulk` →
 * `precedence_bulk`, `settings` (`trackClicks` / `trackOpens` /
 * `trackContent`, or MailerSend's own snake_case keys; boolean values only)
 * → `settings` (`track_clicks` / `track_opens` / `track_content`).
 * `_passthrough.body` still wins on conflict.
 *
 * Each `personalization` entry is `['email' => string, 'data' => array]`; an
 * entry without `data` is rejected before sending.
 *
 * @phpstan-type Personalization list<array<string,mixed>>
 * @phpstan-type Variables list<array{email:string,substitutions:list<array{var:string,value:string}>}>
 * @phpstan-type Settings array<string,mixed>
 */
final readonly class MailersendNarrowedInput extends EmailSendInput
{
    /**
     * @param list<string>|null $cc
     * @param list<string>|null $bcc
     * @param list<EmailAttachment>|null $attachments
     * @param array<string,string>|null $headers
     * @param list<string>|null $tags
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param Personalization|null $personalization
     * @param Variables|null $variables
     * @param Settings|null $settings
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
        public ?array $personalization = null,
        /**
         * @deprecated MailerSend removed simple personalization (`variables`)
         *             from its email API; this field is never sent. Use
         *             `personalization` instead.
         */
        public ?array $variables = null,
        public ?int $sendAt = null,
        public ?bool $precedenceBulk = null,
        public ?array $settings = null,
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
