<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailgun;

use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;

/**
 * Mailgun-specific narrowed input. Captures Mailgun-only form options below
 * the ≥90% baseline per the ≥90%-of-providers baseline rule.
 *
 * Wire mapping: `oTracking` → `o:tracking`, `oTrackingClicks` →
 * `o:tracking-clicks`, `oTrackingOpens` → `o:tracking-opens`, `oDkim` →
 * `o:dkim` (each `yes`/`no`); `oTestmode: true` → `o:testmode=yes`;
 * `oDeliverytime` (RFC 2822 date) → `o:deliverytime`; each `vCustomVars`
 * entry → `v:<name>`; `template` → `template`; `tVariables` → `t:variables`
 * (JSON); `tVersion` → `t:version`; `tText` (`'yes'`) → `t:text`.
 * `_passthrough.body` still wins on conflict.
 */
final readonly class MailgunNarrowedInput extends EmailSendInput
{
    /**
     * @param list<string>|null $cc
     * @param list<string>|null $bcc
     * @param list<EmailAttachment>|null $attachments
     * @param array<string,string>|null $headers
     * @param list<string>|null $tags
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param array<string,mixed>|null $vCustomVars  A non-string value is sent as JSON.
     * @param array<string,mixed>|null $tVariables   Any JSON dictionary.
     * @param bool|string|null $oTracking        true, false, or `'htmlonly'` (rewrite links in the HTML part only).
     * @param bool|string|null $oTrackingClicks  true, false, or `'htmlonly'`.
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
        public bool|string|null $oTracking = null,
        public bool|string|null $oTrackingClicks = null,
        public ?bool $oTrackingOpens = null,
        public ?string $oDeliverytime = null,
        public ?bool $oTestmode = null,
        public ?bool $oDkim = null,
        public ?array $vCustomVars = null,
        public ?string $template = null,
        public ?array $tVariables = null,
        public ?string $tVersion = null,
        public ?string $tText = null,
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
