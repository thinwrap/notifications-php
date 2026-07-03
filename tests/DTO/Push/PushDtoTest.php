<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\DTO\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushSendResult;
use Thinwrap\Notifications\DTO\Push\PushStatus;

#[CoversClass(\Thinwrap\Notifications\DTO\Push\PushSendInput::class)]
#[CoversClass(\Thinwrap\Notifications\DTO\Push\PushSendResult::class)]
#[CoversClass(\Thinwrap\Notifications\DTO\Push\PushStatus::class)]
final class PushDtoTest extends TestCase
{
    public function testSendInputBaselineFourFields(): void
    {
        $input = new PushSendInput(
            to: 'device-token',
            title: 'Title',
            body: 'Body',
            data: ['key' => 'value'],
        );

        self::assertSame('device-token', $input->to);
        self::assertSame('Title', $input->title);
        self::assertSame('Body', $input->body);
        self::assertSame(['key' => 'value'], $input->data);
    }

    public function testSendInputIsReadonlyButNotFinal(): void
    {
        $rc = new ReflectionClass(PushSendInput::class);

        self::assertTrue($rc->isReadOnly());
        self::assertFalse($rc->isFinal());
    }

    public function testSendResultIsFinalReadonly(): void
    {
        $rc = new ReflectionClass(PushSendResult::class);

        self::assertTrue($rc->isFinal());
        self::assertTrue($rc->isReadOnly());
    }

    public function testStatusWireValues(): void
    {
        self::assertSame('sent', PushStatus::Sent->value);
        self::assertSame('queued', PushStatus::Queued->value);
        self::assertSame('rejected', PushStatus::Rejected->value);
        self::assertSame('suppressed', PushStatus::Suppressed->value);
        self::assertSame('unknown', PushStatus::Unknown->value);
        self::assertCount(5, PushStatus::cases());
    }
}
