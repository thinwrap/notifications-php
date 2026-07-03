<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\Util\Passthrough;

#[CoversClass(\Thinwrap\Notifications\Util\Passthrough::class)]
final class PassthroughTest extends TestCase
{
    public function testNullPassthroughReturnsBaseUnchanged(): void
    {
        $result = Passthrough::merge(['body' => ['a' => 1]], null);

        self::assertSame(['a' => 1], $result['body']);
        self::assertSame([], $result['headers']);
        self::assertSame([], $result['query']);
    }

    public function testConsumerKeysWinAtSamePath(): void
    {
        $result = Passthrough::merge(
            ['body' => ['a' => 1]],
            ['body' => ['a' => 2]],
        );

        self::assertSame(['a' => 2], $result['body']);
    }

    public function testNestedAssocDeepMerge(): void
    {
        $result = Passthrough::merge(
            ['body' => ['nested' => ['x' => 1, 'y' => 2]]],
            ['body' => ['nested' => ['y' => 99, 'z' => 3]]],
        );

        self::assertSame(['nested' => ['x' => 1, 'y' => 99, 'z' => 3]], $result['body']);
    }

    public function testNumericArrayOverwrites(): void
    {
        $result = Passthrough::merge(
            ['body' => ['list' => [1, 2, 3]]],
            ['body' => ['list' => [4, 5]]],
        );

        self::assertSame(['list' => [4, 5]], $result['body']);
    }

    public function testHeadersShallowMerge(): void
    {
        $result = Passthrough::merge(
            ['headers' => ['A' => 'x', 'B' => 'y']],
            ['headers' => ['B' => 'z', 'C' => 'w']],
        );

        self::assertSame(['A' => 'x', 'B' => 'z', 'C' => 'w'], $result['headers']);
    }

    public function testQueryShallowMerge(): void
    {
        $result = Passthrough::merge(
            ['query' => ['p' => '1', 'q' => '2']],
            ['query' => ['q' => '99', 'r' => '3']],
        );

        self::assertSame(['p' => '1', 'q' => '99', 'r' => '3'], $result['query']);
    }
}
