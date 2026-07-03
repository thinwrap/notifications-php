<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailtrap;

/**
 * Mailtrap connector configuration.
 *
 * `$mode` is REQUIRED — no default. The failure mode is asymmetric: a
 * production email accidentally routed to the sandbox is a quiet bug; a
 * sandbox email accidentally hitting production is a real-user-impact bug
 * (explicit-over-implicit).
 *
 * `$inboxId` is REQUIRED when `$mode === MailtrapMode::Sandbox` and FORBIDDEN
 * when `$mode === MailtrapMode::Production`. The constraint is enforced at
 * construction time by `MailtrapEmailConnector::validateConfig()` (the only
 * v1.0 PHP connector that runs a synchronous constructor-time validator).
 */
final readonly class MailtrapConfig
{
    public function __construct(
        public string $apiToken,
        public MailtrapMode $mode,
        public ?string $inboxId = null,
        public ?string $from = null,
        public ?string $senderName = null,
    ) {}
}
