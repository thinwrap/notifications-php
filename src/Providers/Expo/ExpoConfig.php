<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Expo;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * Stub config for Expo push notifications. Real shape lands in a later release.
 *
 * `accessToken` is optional — Expo accepts unauthenticated requests for
 * non-restricted projects. The connector's `getAuthHeaders()` returns `[]`
 * when null.
 */
final readonly class ExpoConfig
{
    use RedactsSecrets;

    public function __construct(#[\SensitiveParameter] public ?string $accessToken = null) {}
}
