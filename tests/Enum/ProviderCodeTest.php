<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\Enum\ProviderCode;

#[CoversClass(\Thinwrap\Notifications\Enum\ProviderCode::class)]
final class ProviderCodeTest extends TestCase
{
    public function testHasExactlySixCases(): void
    {
        self::assertCount(6, ProviderCode::cases());
    }

    /**
     * @return array<string, array{ProviderCode, string}>
     */
    public static function wireValueProvider(): array
    {
        return [
            'InvalidRecipient'    => [ProviderCode::InvalidRecipient, 'invalid_recipient'],
            'RateLimited'         => [ProviderCode::RateLimited, 'rate_limited'],
            'AuthFailed'          => [ProviderCode::AuthFailed, 'auth_failed'],
            'ProviderUnavailable' => [ProviderCode::ProviderUnavailable, 'provider_unavailable'],
            'InvalidRequest'      => [ProviderCode::InvalidRequest, 'invalid_request'],
            'Unknown'             => [ProviderCode::Unknown, 'unknown'],
        ];
    }

    #[DataProvider('wireValueProvider')]
    public function testWireValues(ProviderCode $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    public function testBackedEnumBehavior(): void
    {
        self::assertSame(ProviderCode::RateLimited, ProviderCode::from('rate_limited'));
        self::assertNull(ProviderCode::tryFrom('nonsense'));
    }
}
