<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\WhatsappBusiness;

use Thinwrap\Notifications\DTO\Chat\ChatSendInput;

/**
 * WhatsApp Business-narrowed `ChatSendInput`. Mirror of TS
 * `WhatsAppBusinessNarrowedInput`.
 *
 * `to` is REQUIRED — E.164 phone number (e.g. `'+14155552671'`).
 * Body-vs-template/interactive contract: when `type === 'template'` or
 * `type === 'interactive'`, `body` is ignored.
 */
final readonly class WhatsappBusinessChatSendInput extends ChatSendInput
{
    /**
     * @param string $type            One of: text|template|interactive|image|document|video|audio|location|contacts.
     * @param array<string,mixed>|null $template
     * @param array<string,mixed>|null $interactive
     * @param array{messageId:string}|null $context  Reply-to-message context.
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        string $to,
        string $body,
        public string $type = 'text',
        public ?array $template = null,
        public ?array $interactive = null,
        public ?array $context = null,
        public ?bool $previewUrl = null,
        ?array $_passthrough = null,
    ) {
        parent::__construct($to, $body, $_passthrough);
    }
}
