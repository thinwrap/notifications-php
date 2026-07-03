<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Twilio;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

/**
 * Twilio-narrowed extension of `SmsSendInput`. Below-baseline fields per locked
 * memory the ≥90%-of-providers baseline rule. `mediaUrl` is `list<string>`
 * and encoded as MULTIPLE `MediaUrl=` form fields by the connector — never
 * comma-joined.
 */
final readonly class TwilioNarrowedInput extends SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param list<string>|null $mediaUrl
     * @param list<string>|null $persistentAction
     */
    public function __construct(
        string $to,
        string $body,
        ?string $from = null,
        ?array $_passthrough = null,
        public ?string $messagingServiceSid = null,
        public ?array $mediaUrl = null,
        public ?string $statusCallback = null,
        public ?string $applicationSid = null,
        public ?string $maxPrice = null,
        public ?bool $provideFeedback = null,
        public ?int $validityPeriod = null,
        public ?bool $forceDelivery = null,
        public ?string $contentRetention = null,
        public ?string $addressRetention = null,
        public ?bool $smartEncoded = null,
        public ?array $persistentAction = null,
        public ?bool $shortenUrls = null,
        public ?string $scheduleType = null,
        public ?string $sendAt = null,
        public ?bool $sendAsMms = null,
        public ?string $contentVariables = null,
        public ?string $riskCheck = null,
        public ?string $contentSid = null,
    ) {
        parent::__construct(to: $to, body: $body, from: $from, _passthrough: $_passthrough);
    }
}
