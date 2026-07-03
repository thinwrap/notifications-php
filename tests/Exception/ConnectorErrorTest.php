<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;

#[CoversClass(\Thinwrap\Notifications\Exception\ConnectorError::class)]
final class ConnectorErrorTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $err = new ConnectorError(ProviderCode::Unknown);

        self::assertInstanceOf(\RuntimeException::class, $err);
    }

    public function testConstructorFieldOrder(): void
    {
        $cause = ['body' => ['error' => 'x'], 'retryAfter' => '60'];
        $err = new ConnectorError(
            providerCode: ProviderCode::RateLimited,
            providerMessage: 'Sendgrid throttled',
            cause: $cause,
            statusCode: 429,
        );

        self::assertSame(ProviderCode::RateLimited, $err->providerCode);
        self::assertSame('Sendgrid throttled', $err->providerMessage);
        self::assertSame($cause, $err->cause);
        self::assertSame(429, $err->statusCode);
    }

    public function testGetMessageReturnsProviderMessage(): void
    {
        $err = new ConnectorError(
            ProviderCode::AuthFailed,
            'Sendgrid rejected: invalid API key',
        );

        self::assertSame('Sendgrid rejected: invalid API key', $err->getMessage());
    }

    public function testGetMessageFallsBackToDefault(): void
    {
        $err = new ConnectorError(ProviderCode::Unknown);

        self::assertSame('Connector error', $err->getMessage());
    }

    public function testCauseAcceptsArbitraryShape(): void
    {
        $cause = ['body' => ['error' => 'x'], 'retryAfter' => '60'];
        $err = new ConnectorError(ProviderCode::RateLimited, 'rate limited', $cause);

        self::assertSame($cause, $err->cause);
    }

    /**
     * Regression guard freezing the no-structured-retry-field rule
     * contract: ConnectorError must NOT have a structured `retryAfterSeconds`
     * field. Drift surfaces as a CI failure.
     */
    public function testHasNoRetryAfterSecondsProperty(): void
    {
        $rc = new ReflectionClass(ConnectorError::class);

        self::assertFalse($rc->hasProperty('retryAfterSeconds'));
    }
}
