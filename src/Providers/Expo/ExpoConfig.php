<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Expo;

/**
 * Stub config for Expo push notifications. Real shape lands in a later release.
 *
 * `accessToken` is optional — Expo accepts unauthenticated requests for
 * non-restricted projects. The connector's `getAuthHeaders()` returns `[]`
 * when null.
 */
final readonly class ExpoConfig
{
    public function __construct(public ?string $accessToken = null) {}
}
