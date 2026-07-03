<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\DTO\Chat;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatSendResult;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;

#[CoversClass(\Thinwrap\Notifications\DTO\Chat\ChatSendInput::class)]
#[CoversClass(\Thinwrap\Notifications\DTO\Chat\ChatSendResult::class)]
#[CoversClass(\Thinwrap\Notifications\DTO\Chat\ChatStatus::class)]
final class ChatDtoTest extends TestCase
{
    public function testSendInputNullToIsConstructivelyValid(): void
    {
        // Webhook-URL-as-auth providers (Slack,
        // Discord, Msteams, Mattermost, GoogleChat) omit `to` entirely.
        $input = new ChatSendInput(to: null, body: 'hello');

        self::assertNull($input->to);
        self::assertSame('hello', $input->body);
    }

    public function testSendInputWithTo(): void
    {
        $input = new ChatSendInput(to: '@channel', body: 'hi');

        self::assertSame('@channel', $input->to);
    }

    public function testSendInputIsReadonlyButNotFinal(): void
    {
        $rc = new ReflectionClass(ChatSendInput::class);

        self::assertTrue($rc->isReadOnly());
        self::assertFalse($rc->isFinal());
    }

    public function testSendResultIsFinalReadonly(): void
    {
        $rc = new ReflectionClass(ChatSendResult::class);

        self::assertTrue($rc->isFinal());
        self::assertTrue($rc->isReadOnly());
    }

    public function testStatusWireValues(): void
    {
        self::assertSame('sent', ChatStatus::Sent->value);
        self::assertSame('queued', ChatStatus::Queued->value);
        self::assertSame('rejected', ChatStatus::Rejected->value);
        self::assertSame('suppressed', ChatStatus::Suppressed->value);
        self::assertSame('unknown', ChatStatus::Unknown->value);
        self::assertCount(5, ChatStatus::cases());
    }
}
