<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\DTO\Sms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsSendResult;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;

#[CoversClass(\Thinwrap\Notifications\DTO\Sms\SmsSendInput::class)]
#[CoversClass(\Thinwrap\Notifications\DTO\Sms\SmsSendResult::class)]
#[CoversClass(\Thinwrap\Notifications\DTO\Sms\SmsStatus::class)]
final class SmsDtoTest extends TestCase
{
    public function testSendInputBaselineThreeFields(): void
    {
        $input = new SmsSendInput(to: '+15551234567', body: 'hi', from: '+15557654321');

        self::assertSame('+15551234567', $input->to);
        self::assertSame('hi', $input->body);
        self::assertSame('+15557654321', $input->from);
    }

    public function testSendInputFromIsOptional(): void
    {
        $input = new SmsSendInput(to: '+15551234567', body: 'hi');

        self::assertNull($input->from);
    }

    public function testSendInputIsReadonlyButNotFinal(): void
    {
        $rc = new ReflectionClass(SmsSendInput::class);

        self::assertTrue($rc->isReadOnly());
        self::assertFalse($rc->isFinal());
    }

    public function testSendResultIsFinalReadonly(): void
    {
        $rc = new ReflectionClass(SmsSendResult::class);

        self::assertTrue($rc->isFinal());
        self::assertTrue($rc->isReadOnly());
    }

    /**
     * @return array<string, array{SmsStatus, string}>
     */
    public static function statusWireValueProvider(): array
    {
        return [
            'sent'       => [SmsStatus::Sent, 'sent'],
            'queued'     => [SmsStatus::Queued, 'queued'],
            'rejected'   => [SmsStatus::Rejected, 'rejected'],
            'suppressed' => [SmsStatus::Suppressed, 'suppressed'],
            'unknown'    => [SmsStatus::Unknown, 'unknown'],
        ];
    }

    #[DataProvider('statusWireValueProvider')]
    public function testStatusWireValuesMatchTsCanonical(SmsStatus $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    public function testStatusHasExactlyFiveCases(): void
    {
        self::assertCount(5, SmsStatus::cases());
    }
}
