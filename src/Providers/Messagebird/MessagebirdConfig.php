<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Messagebird;

use Thinwrap\Notifications\Util\RedactsSecrets;

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
    use RedactsSecrets;

    public function __construct(
        #[\SensitiveParameter]
        public string $accessKey,
        public ?string $from = null,
    ) {}
}
