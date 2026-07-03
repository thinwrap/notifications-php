<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Fcm;

use Thinwrap\Notifications\Contract\TokenCacheHook;

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
        $this->projectId = $serviceAccount['project_id'];
        $this->clientEmail = $serviceAccount['client_email'];
        $this->privateKey = $serviceAccount['private_key'];
    }
}
