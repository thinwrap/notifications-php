<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Fcm;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Base\BaseConnector;
use Thinwrap\Notifications\Contract\PushConnectorInterface;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushSendResult;
use Thinwrap\Notifications\DTO\Push\PushStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Util\Passthrough;

/**
 * Firebase Cloud Messaging (FCM HTTP v1) push connector — PHP, stateless RS256
 * JWT + OAuth2 access token, no token caching unless a `TokenCacheHook` is
 * supplied via `FcmConfig`.
 *
 * Mirrors TS `FcmPushConnector` (notifications-ts/src/providers/fcm/fcm.connector.ts)
 * line-for-line: same auth flow, same wire shape, same error mapping.
 *
 * **Stateless signing:** the connector holds no token state. Every `send()` either reads
 * the consumer's optional `tokenCache` hook (with key `fcm:<projectId>`) OR
 * signs a fresh RS256 JWT and exchanges it at `https://oauth2.googleapis.com/token`.
 *
 * Cryptography uses only PHP `ext-openssl` (`openssl_pkey_get_private` +
 * `openssl_sign` with `OPENSSL_ALGO_SHA256`) — no `firebase/php-jwt`, no
 * `google/auth` dependency (zero-runtime-deps).
 */
final class FcmPushConnector extends BaseConnector implements PushConnectorInterface
{
    public readonly string $id;

    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(
        private readonly FcmConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'fcm';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(PushSendInput $input): PushSendResult
    {
        $accessToken = $this->getAccessTokenViaHookOrFresh();

        $message = $this->buildFcmMessage($input);

        $connectorBody = ['message' => $message];
        $connectorHeaders = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $accessToken,
        ];

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => $connectorHeaders, 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $url = "https://fcm.googleapis.com/v1/projects/{$this->config->projectId}/messages:send";
        if ($merged['query'] !== []) {
            $url .= '?' . http_build_query($merged['query']);
        }

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            /** @var array<string,mixed>|null $errBody */
            $errBody = $this->decodeJson($response);
            throw $this->mapVendorError($status, $errBody, $response);
        }

        /** @var array<string,mixed>|null $raw */
        $raw = $this->decodeJson($response);
        $providerMessageId = null;
        if (is_array($raw) && isset($raw['name']) && is_string($raw['name'])) {
            $providerMessageId = $raw['name'];
        }

        return new PushSendResult(
            success: true,
            status: PushStatus::Sent,
            providerMessageId: $providerMessageId,
            raw: $raw,
        );
    }

    /**
     * Mirror of TS `getAccessTokenViaHookOrFresh()`. Three branches:
     *   - No hook: mint fresh on every call.
     *   - Hook returns fresh entry (`expiresAt > time()`): reuse.
     *   - Hook returns stale or null: mint fresh + write back via hook.
     */
    private function getAccessTokenViaHookOrFresh(): string
    {
        $cacheKey = "fcm:{$this->config->projectId}";

        if ($this->config->tokenCache !== null) {
            $cached = $this->config->tokenCache->get($cacheKey);
            if ($cached !== null && $cached['expiresAt'] > time()) {
                return $cached['token'];
            }
        }

        [$accessToken, $expiresInSeconds] = $this->mintFreshToken();

        if ($this->config->tokenCache !== null) {
            $this->config->tokenCache->set($cacheKey, [
                'token'     => $accessToken,
                'expiresAt' => time() + $expiresInSeconds,
            ]);
        }

        return $accessToken;
    }

    /**
     * Sign an RS256 JWT for Google's OAuth 2.0 service-account flow and
     * exchange it at the OAuth endpoint. Returns `[accessToken, expiresIn]`.
     *
     * @return array{0: string, 1: int}
     */
    private function mintFreshToken(): array
    {
        $now = time();
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR);
        $claims = json_encode([
            'iss'   => $this->config->clientEmail,
            'scope' => self::FCM_SCOPE,
            'aud'   => self::OAUTH_TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], JSON_THROW_ON_ERROR);

        $unsigned = self::base64UrlEncode($header) . '.' . self::base64UrlEncode($claims);

        $key = openssl_pkey_get_private($this->config->privateKey);
        if ($key === false) {
            throw new ConnectorError(
                providerCode: ProviderCode::AuthFailed,
                providerMessage: 'FCM: invalid private key (openssl_pkey_get_private failed)',
                cause: ['opensslError' => openssl_error_string()],
                statusCode: null,
            );
        }

        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new ConnectorError(
                providerCode: ProviderCode::AuthFailed,
                providerMessage: 'FCM: openssl_sign failed',
                cause: ['opensslError' => openssl_error_string()],
                statusCode: null,
            );
        }

        $jwt = $unsigned . '.' . self::base64UrlEncode($signature);

        $response = $this->sendPostForm(self::OAUTH_TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $status = $response->getStatusCode();
        $body = $this->decodeJson($response);

        if ($status < 200 || $status >= 300 || !is_array($body) || !isset($body['access_token'], $body['expires_in'])) {
            throw new ConnectorError(
                providerCode: ProviderCode::AuthFailed,
                providerMessage: 'FCM OAuth token exchange failed: HTTP ' . $status,
                cause: ['raw' => $body],
                statusCode: $status,
            );
        }

        $accessToken = $body['access_token'];
        $expiresIn = $body['expires_in'];
        if (!is_string($accessToken) || $accessToken === '' || !is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            throw new ConnectorError(
                providerCode: ProviderCode::AuthFailed,
                providerMessage: 'Invalid OAuth token response',
                cause: ['raw' => $body],
                statusCode: $status,
            );
        }

        return [$accessToken, (int) $expiresIn];
    }

    /**
     * Build the FCM HTTP v1 `Message` object from `FcmPushSendInput` (or
     * the base `PushSendInput`). Mirrors TS `buildFcmMessage`.
     *
     * @return array<string,mixed>
     */
    private function buildFcmMessage(PushSendInput $input): array
    {
        $message = ['token' => $input->to];

        if (($input->title ?? '') !== '' || ($input->body ?? '') !== '') {
            $notification = [];
            if (($input->title ?? '') !== '') {
                $notification['title'] = $input->title;
            }
            if (($input->body ?? '') !== '') {
                $notification['body'] = $input->body;
            }
            $message['notification'] = $notification;
        }

        if ($input->data !== null) {
            $message['data'] = $input->data;
        }

        $ttl = $input instanceof FcmPushSendInput ? $input->ttl : null;
        $android = $input instanceof FcmPushSendInput ? $input->android : null;

        if ($ttl !== null || $android !== null) {
            $androidBlock = $android ?? [];
            if ($ttl !== null && !isset($androidBlock['ttl'])) {
                $androidBlock['ttl'] = "{$ttl}s";
            }
            $message['android'] = $androidBlock;
        }

        if ($input instanceof FcmPushSendInput) {
            if ($input->apns !== null) {
                $message['apns'] = $input->apns;
            }
            if ($input->webpush !== null) {
                $message['webpush'] = $input->webpush;
            }
            if ($input->fcm_options !== null) {
                $message['fcm_options'] = $input->fcm_options;
            }
        }

        return $message;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $fcmStatus = '';
        $errorMessage = "FCM HTTP {$statusCode}";

        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $err = $body['error'];
            if (isset($err['status']) && is_string($err['status'])) {
                $fcmStatus = $err['status'];
            }
            if (isset($err['message']) && is_string($err['message']) && $err['message'] !== '') {
                $errorMessage = $err['message'];
            }
        }

        $providerCode = $this->classifyError($statusCode, $fcmStatus);

        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $providerMessage = $parsedSeconds !== null
            ? "{$errorMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $errorMessage;

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            cause: [
                'raw'       => $body,
                'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
                'retryAfterSeconds' => $parsedSeconds,
            ],
            statusCode: $statusCode,
        );
    }

    private function classifyError(int $statusCode, string $fcmStatus): ProviderCode
    {
        if ($statusCode === 401 || $statusCode === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 404) {
            return ProviderCode::InvalidRecipient;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode === 500 || $statusCode === 503) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($statusCode === 400) {
            return ProviderCode::InvalidRequest;
        }

        switch ($fcmStatus) {
            case 'UNAUTHENTICATED':
            case 'SENDER_ID_MISMATCH':
                return ProviderCode::AuthFailed;
            case 'INVALID_ARGUMENT':
                return ProviderCode::InvalidRequest;
            case 'NOT_FOUND':
                return ProviderCode::InvalidRecipient;
            case 'QUOTA_EXCEEDED':
            case 'RESOURCE_EXHAUSTED':
                return ProviderCode::RateLimited;
            case 'UNAVAILABLE':
            case 'INTERNAL':
                return ProviderCode::ProviderUnavailable;
        }

        return ProviderCode::Unknown;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJson(ResponseInterface $response): ?array
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Per casing-locality: outlier crypto helper stays private on the connector
     * that owns it. The APNs connector duplicates a parallel 3-liner — accepted.
     */
    private static function base64UrlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
