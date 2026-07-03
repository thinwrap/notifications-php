<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sns;

use Thinwrap\Notifications\DTO\Sms\SmsSendInput;

/**
 * SNS-narrowed input. SNS supports either phone-number publishes (`to`) or
 * topic publishes (`topicArn`). When `topicArn` is set, `to` is not required.
 *
 * SMS-convenience fields (`smsType`, `senderId`, `maxPrice`) are flattened
 * into the indexed `MessageAttributes.entry.N.*` AWS Query form by the
 * connector under the documented `AWS.SNS.SMS.*` attribute names.
 *
 * `$messageAttributes` shape: `Record<string, {DataType, StringValue?, BinaryValue?}>`.
 */
final readonly class SnsNarrowedInput extends SmsSendInput
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     * @param array<string,array{DataType:string,StringValue?:string,BinaryValue?:string}>|null $messageAttributes
     */
    public function __construct(
        string $to,
        string $body,
        ?string $from = null,
        ?array $_passthrough = null,
        public ?string $topicArn = null,
        public ?string $messageStructure = null,
        public ?array $messageAttributes = null,
        public ?string $smsType = null,
        public ?string $senderId = null,
        public ?string $maxPrice = null,
    ) {
        parent::__construct(to: $to, body: $body, from: $from, _passthrough: $_passthrough);
    }
}
