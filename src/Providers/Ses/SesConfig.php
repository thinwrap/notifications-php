<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Ses;

/**
 * AWS SES connector configuration.
 *
 * `$region` is intentionally typed as `string` (not a backed enum) — AWS adds
 * new regions roughly twice a year and a closed enum would force a Thinwrap
 * point-release per region. PHPStan level 8 plus the docblock note below is
 * sufficient typing; consumers passing an obviously-wrong region get a DNS
 * resolution failure at HTTP send time. Mirrors AWS PHP SDK behavior.
 *
 * `$sessionToken` is optional and carries STS temporary credentials when set;
 * the connector forwards it as `X-Amz-Security-Token` and includes it in the
 * Sig V4 SignedHeaders list per AWS spec.
 *
 * `$from` / `$senderName` mirror the other email connectors' config-level
 * sender fallback: when `EmailSendInput.from` is empty the
 * connector falls back to `$from`, optionally wrapped as `Name <addr>` when
 * `$senderName` is set.
 */
final readonly class SesConfig
{
    /**
     * @param string $region            AWS region identifier, e.g. 'us-east-1', 'eu-west-1'.
     * @param string $accessKeyId       AWS access key ID.
     * @param string $secretAccessKey   AWS secret access key.
     * @param string|null $sessionToken Optional STS temporary credential session token.
     * @param string|null $from         Optional default sender address (fallback when `EmailSendInput.from` is empty).
     * @param string|null $senderName   Optional display name wrapped around the sender address as `Name <addr>`.
     */
    public function __construct(
        public string $region,
        public string $accessKeyId,
        public string $secretAccessKey,
        public ?string $sessionToken = null,
        public ?string $from = null,
        public ?string $senderName = null,
    ) {}
}
