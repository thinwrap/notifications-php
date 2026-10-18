<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Ses;

use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * AWS SES connector configuration.
 *
 * `$region` is intentionally typed as `string` (not a backed enum) — AWS adds
 * new regions roughly twice a year and a closed enum would force a Thinwrap
 * point-release per region. The connector checks only the shape of a region
 * name before sending (it becomes the host the signed request goes to), so an
 * unknown-but-well-formed region still fails at DNS time, as in the AWS SDKs.
 *
 * `$sessionToken` is optional and carries STS temporary credentials when set;
 * the connector forwards it as `X-Amz-Security-Token` and includes it in the
 * Sig V4 SignedHeaders list per AWS spec.
 *
 * `$from` / `$senderName` mirror the other email connectors' config-level
 * sender fallback: when `EmailSendInput.from` is empty the
 * connector falls back to `$from`, with `$senderName` as its display name
 * (quoted or RFC 2047-encoded as needed).
 */
final readonly class SesConfig
{
    use RedactsSecrets;

    /**
     * @param string $region            AWS region identifier, e.g. 'us-east-1', 'eu-west-1'.
     * @param string $accessKeyId       AWS access key ID.
     * @param string $secretAccessKey   AWS secret access key.
     * @param string|null $sessionToken Optional STS temporary credential session token.
     * @param string|null $from         Optional default sender address (fallback when `EmailSendInput.from` is empty).
     * @param string|null $senderName   Optional display name for the sender address.
     */
    public function __construct(
        public string $region,
        public string $accessKeyId,
        #[\SensitiveParameter]
        public string $secretAccessKey,
        #[\SensitiveParameter]
        public ?string $sessionToken = null,
        public ?string $from = null,
        public ?string $senderName = null,
    ) {}
}
