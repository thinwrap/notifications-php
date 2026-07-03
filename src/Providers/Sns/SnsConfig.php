<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sns;

/**
 * AWS SNS SMS connector configuration. `$region` is typed `string` (not enum)
 * to avoid forcing a point-release for every AWS region addition — same
 * decision as `SesConfig`.
 *
 * Hand-rolled Sig V4 signing (no `aws/aws-sdk-php` dependency). `$sessionToken`
 * is optional and carries STS temporary credentials when set; the connector
 * forwards it as `X-Amz-Security-Token` and includes it in the Sig V4
 * SignedHeaders list per AWS spec.
 */
final readonly class SnsConfig
{
    public function __construct(
        public string $region,
        public string $accessKeyId,
        public string $secretAccessKey,
        public ?string $sessionToken = null,
    ) {}
}
