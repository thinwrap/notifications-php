<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\D7networks;

/**
 * D7 Networks SMS connector configuration. Bearer auth against the global
 * endpoint `https://api.d7networks.com/messages/v1/send`. No regional
 * clustering — single host for all customers.
 */
final readonly class D7networksConfig
{
    public function __construct(
        public string $apiToken,
        public ?string $from = null,
    ) {}
}
