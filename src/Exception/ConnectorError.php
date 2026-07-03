<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Exception;

use Thinwrap\Notifications\Enum\ProviderCode;

/**
 * Unified error type for all notification connector failures.
 *
 * Mirrors the TS `ConnectorError` (cross-language parity). There is NO
 * top-level structured `retryAfterSeconds` field — the wrapper performs no
 * retry; retry/back-off lives entirely with the consumer.
 *
 * `cause` is loosely typed (`mixed`) so connector-specific structures fit; the
 * canonical shape (parity with TS) is:
 *   `['raw' => mixed, 'retryAfter' => string|int|null, 'retryAfterSeconds' => int|null]`
 * where `raw` is the parsed vendor body (or transport-failure detail),
 * `retryAfter` is the RAW Retry-After value (header string, or body int for
 * Telegram/Discord) left un-normalized, and `retryAfterSeconds` is the parsed
 * seconds (also echoed in `providerMessage`). Connectors carrying a richer cause
 * (e.g., Discord's body-sourced `retryAfterBody` float) extend this shape
 * additively without changing the contract.
 */
final class ConnectorError extends \RuntimeException
{
    public function __construct(
        public readonly ProviderCode $providerCode,
        public readonly ?string $providerMessage = null,
        public readonly mixed $cause = null,
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct($providerMessage ?? 'Connector error');
    }
}
