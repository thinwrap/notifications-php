<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Fcm;

use Thinwrap\Notifications\Contract\TokenCacheHook;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;

/**
 * Firebase Cloud Messaging (FCM HTTP v1) configuration.
 *
 * Mirrors the TS sibling `FcmConfig` (notifications-ts/src/providers/fcm/fcm.config.ts);
 * `clientEmail` + `privateKey` map 1:1 to the `client_email` + `private_key`
 * fields of a Google service-account JSON. For convenience, consumers may
 * pass the parsed service-account JSON array via the `$serviceAccount`
 * constructor parameter — the connector extracts the three needed fields.
 *
 * For security, and because the wrapper holds no state, the wrapper signs +
 * exchanges a fresh OAuth access token on
 * every `.send()` by default. The optional `tokenCache` hook amortizes the
 * cost across calls; cache key is exactly `'fcm:' + projectId`.
 *
 * @phpstan-type ServiceAccount array{type?:string,project_id:string,private_key_id?:string,private_key:string,client_email:string,client_id?:string,auth_uri?:string,token_uri?:string}
 */
final readonly class FcmConfig
{
    public string $projectId;

    public string $clientEmail;

    public string $privateKey;

    /**
     * @param ServiceAccount $serviceAccount Parsed service-account JSON (the
     *                                       wrapper does NOT read files).
     */
    public function __construct(
        public array $serviceAccount,
        public ?TokenCacheHook $tokenCache = null,
    ) {
        // Validate at construction: a malformed service-account array (e.g. from
        // a truncated JSON file) must raise a clean typed `InvalidRequest`
        // rather than a PHP undefined-key warning + `TypeError` on the null →
        // typed-string property assignment below.
        $this->projectId = self::requireField($serviceAccount, 'project_id');
        $this->clientEmail = self::requireField($serviceAccount, 'client_email');
        $this->privateKey = self::requireField($serviceAccount, 'private_key');
    }

    /**
     * Read a required service-account field, throwing `InvalidRequest` when it
     * is missing, non-string, or empty.
     *
     * @param array<string,mixed> $serviceAccount
     */
    private static function requireField(array $serviceAccount, string $key): string
    {
        $value = $serviceAccount[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "FCM: service account is missing required field '{$key}'",
                statusCode: 400,
            );
        }

        return $value;
    }
}
