<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sns;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * AWS SNS SMS connector configuration. `$region` is typed `string` (not enum)
 * to avoid forcing a point-release for every AWS region addition — same
 * decision as `SesConfig`, including the shape check made before sending.
 *
 * Hand-rolled Sig V4 signing (no `aws/aws-sdk-php` dependency). `$sessionToken`
 * is optional and carries STS temporary credentials when set; the connector
 * forwards it as `X-Amz-Security-Token` and includes it in the Sig V4
 * SignedHeaders list per AWS spec.
 */
final readonly class SnsConfig
{
    use RedactsSecrets;

    public function __construct(
        public string $region,
        public string $accessKeyId,
        #[\SensitiveParameter]
        public string $secretAccessKey,
        #[\SensitiveParameter]
        public ?string $sessionToken = null,
    ) {}
}
