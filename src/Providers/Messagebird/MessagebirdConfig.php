<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Messagebird;

/**
 * MessageBird (now branded "Bird") SMS connector configuration.
 *
 * The legacy `rest.messagebird.com` API surface remains operational and is the
 * v1.0 baseline — provider ID stays `messagebird` by design (no `bird` alias).
 *
 * `$accessKey` is sent as `Authorization: AccessKey <accessKey>` (custom
 * scheme — same family as Infobip's `App <key>`).
 */
final readonly class MessagebirdConfig
{
    public function __construct(
        public string $accessKey,
        public ?string $from = null,
    ) {}
}
