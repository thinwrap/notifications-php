<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\DTO\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailSendResult;
use Thinwrap\Notifications\DTO\Email\EmailStatus;

#[CoversClass(\Thinwrap\Notifications\DTO\Email\EmailSendInput::class)]
#[CoversClass(\Thinwrap\Notifications\DTO\Email\EmailAttachment::class)]
#[CoversClass(\Thinwrap\Notifications\DTO\Email\EmailSendResult::class)]
final class EmailSendInputTest extends TestCase
{
    public function testConstructsWithRequiredFields(): void
    {
        $input = new EmailSendInput(
            from: 'a@x.test',
            to: 'b@y.test',
            subject: 'Hello',
        );

        self::assertSame('a@x.test', $input->from);
        self::assertSame('b@y.test', $input->to);
        self::assertSame('Hello', $input->subject);
        self::assertNull($input->text);
        self::assertNull($input->html);
    }

    public function testEmailSendInputIsReadonly(): void
    {
        $rc = new ReflectionClass(EmailSendInput::class);

        self::assertTrue($rc->isReadOnly());
    }

    public function testEmailSendInputIsNotFinal(): void
    {
        $rc = new ReflectionClass(EmailSendInput::class);

        // NOT final — per-connector NarrowedInput classes extend this base.
        self::assertFalse($rc->isFinal());
    }

    public function testAttachmentIsFinalReadonly(): void
    {
        $rc = new ReflectionClass(EmailAttachment::class);

        self::assertTrue($rc->isFinal());
        self::assertTrue($rc->isReadOnly());
    }

    public function testResultIsFinalReadonly(): void
    {
        $rc = new ReflectionClass(EmailSendResult::class);

        self::assertTrue($rc->isFinal());
        self::assertTrue($rc->isReadOnly());
    }

    public function testResultStatusIsBackedEnum(): void
    {
        $result = new EmailSendResult(
            success: true,
            status: EmailStatus::Sent,
            providerMessageId: 'msg_x',
            raw: ['id' => 'msg_x'],
        );

        // Per a documented design decision, Email status is the `EmailStatus` backed enum
        // (mirroring SMS/Push/Chat), constraining it to the 5-literal union and
        // preventing off-union values from shipping silently. The wire value
        // remains byte-identical to TS's literal union.
        self::assertSame(EmailStatus::Sent, $result->status);
        self::assertSame('sent', $result->status->value);
    }
}
