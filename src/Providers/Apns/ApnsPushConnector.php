<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Apns;

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
 * Apple Push Notification service (APNs) connector — token-based auth via
 * ES256 JWT signed against an Apple `.p8` private key.
 *
 * Mirrors TS `ApnsPushConnector` (notifications-ts/src/providers/apns/apns.connector.ts).
 *
 * **Cryptographic outlier (PHP-vs-TS divergence in mechanism, not output):**
 * Node's `crypto.sign(..., { dsaEncoding: 'ieee-p1363' })` emits ES256
 * signatures in the IEEE P1363 raw `r||s` format that APNs / JWT ES256
 * require. PHP's `openssl_sign` with `OPENSSL_ALGO_SHA256` against an EC
 * P-256 key emits a DER-encoded `SEQUENCE(INTEGER r, INTEGER s)` instead;
 * the connector converts DER -> P1363 manually via `derToP1363Es256()`.
 * Output bytes are identical to Node's `'ieee-p1363'` mode.
 *
 * **Stateless signing:** no instance state for tokens. Every `send()` either reads the
 * consumer's optional `tokenCache` hook (key `apns:<teamId>:<keyId>:<bundleId>`)
 * or signs a fresh JWT. 50-minute cache TTL leaves a 10-minute safety margin
 * against Apple's documented ~60-minute JWT validity window.
 */
final class ApnsPushConnector extends BaseConnector implements PushConnectorInterface
{
    public readonly string $id;

    /** APNs allows a JWT for up to ~60 minutes; 50 min leaves a safety margin. */
    private const JWT_CACHE_TTL_SECONDS = 50 * 60;

    public function __construct(
        private readonly ApnsConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'apns';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(PushSendInput $input): PushSendResult
    {
        $jwt = $this->getJwtViaHookOrFresh();

        $wireBody = $this->buildApnsBody($input);
        $connectorHeaders = $this->buildApnsHeaders($input, $jwt);

        $merged = Passthrough::merge(
            ['body' => $wireBody, 'headers' => $connectorHeaders, 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $host = $this->config->production
            ? 'api.push.apple.com'
            : 'api.sandbox.push.apple.com';
        $url = "https://{$host}/3/device/" . rawurlencode($input->to);

        // APNs is HTTP/2-only. Pin the request protocol so a curl-backed PSR-18
        // client negotiates h2 — Guzzle otherwise maps the PSR-7 default (1.1) to
        // CURLOPT_HTTP_VERSION_1_1 and every push fails. The injected client must
        // still be HTTP/2-capable (see this connector's README).
        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders, '2.0');
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            /** @var array<string,mixed>|null $errBody */
            $errBody = $this->decodeJson($response);
            throw $this->mapVendorError($status, $errBody, $response);
        }

        // APNs returns an empty body on 200; canonical message ID is the
        // `apns-id` response header.
        $providerMessageId = $response->getHeaderLine('apns-id');

        return new PushSendResult(
            success: true,
            status: PushStatus::Sent,
            providerMessageId: $providerMessageId !== '' ? $providerMessageId : null,
            raw: [],
        );
    }

    private function getJwtViaHookOrFresh(): string
    {
        $cacheKey = "apns:{$this->config->teamId}:{$this->config->keyId}:{$this->config->bundleId}";

        if ($this->config->tokenCache !== null) {
            $cached = $this->config->tokenCache->get($cacheKey);
            if ($cached !== null && $cached['expiresAt'] > time()) {
                return $cached['token'];
            }
        }

        $jwt = $this->createApnsJwt();

        if ($this->config->tokenCache !== null) {
            $this->config->tokenCache->set($cacheKey, [
                'token'     => $jwt,
                'expiresAt' => time() + self::JWT_CACHE_TTL_SECONDS,
            ]);
        }

        return $jwt;
    }

    /**
     * Sign an ES256 JWT for APNs. Header `{alg: ES256, kid: <keyId>, typ: JWT}`;
     * claims `{iss: <teamId>, iat: now}`. Mirrors TS `createApnsJwt`.
     */
    private function createApnsJwt(): string
    {
        $header = json_encode(
            ['alg' => 'ES256', 'kid' => $this->config->keyId, 'typ' => 'JWT'],
            JSON_THROW_ON_ERROR,
        );
        $claims = json_encode(
            ['iss' => $this->config->teamId, 'iat' => time()],
            JSON_THROW_ON_ERROR,
        );

        $unsigned = self::base64UrlEncode($header) . '.' . self::base64UrlEncode($claims);

        $key = openssl_pkey_get_private($this->config->privateKey);
        if ($key === false) {
            throw new ConnectorError(
                providerCode: ProviderCode::AuthFailed,
                providerMessage: 'APNs: invalid private key (openssl_pkey_get_private failed)',
                cause: ['opensslError' => openssl_error_string()],
                statusCode: null,
            );
        }

        $derSignature = '';
        $ok = openssl_sign($unsigned, $derSignature, $key, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new ConnectorError(
                providerCode: ProviderCode::AuthFailed,
                providerMessage: 'APNs: openssl_sign failed',
                cause: ['opensslError' => openssl_error_string()],
                statusCode: null,
            );
        }

        // ES256 over P-256 requires IEEE P1363 (`r || s`, 64 raw bytes); PHP's
        // openssl_sign emits DER-encoded `SEQUENCE(INTEGER r, INTEGER s)`.
        // A malformed DER (from a bad key/openssl output) must surface as a typed
        // ConnectorError, not a raw \RuntimeException the consumer can't catch
        // through the documented error type (mirrors the openssl_sign guard above).
        try {
            $p1363 = self::derToP1363Es256($derSignature);
        } catch (\RuntimeException $e) {
            throw new ConnectorError(
                providerCode: ProviderCode::AuthFailed,
                providerMessage: 'APNs: could not convert the ES256 signature (' . $e->getMessage() . ')',
                cause: ['raw' => ['exception' => $e::class], 'retryAfter' => null],
                statusCode: null,
            );
        }

        return $unsigned . '.' . self::base64UrlEncode($p1363);
    }

    /**
     * Convert ASN.1 DER `SEQUENCE(INTEGER r, INTEGER s)` to IEEE P1363
     * (`r||s`, 32 + 32 = 64 raw bytes for P-256). Manual ASN.1 walk — no
     * extension dependency. Mirrors the bytes Node would emit with
     * `dsaEncoding: 'ieee-p1363'`.
     */
    private static function derToP1363Es256(string $der): string
    {
        $offset = 0;
        $len = strlen($der);

        if ($len < 8 || $der[$offset] !== "\x30") {
            throw new \RuntimeException('APNs: ES256 signature is not a DER SEQUENCE');
        }
        $offset++;

        // SEQUENCE length (short or long form).
        $seqLen = ord($der[$offset]);
        $offset++;
        if ($seqLen & 0x80) {
            $byteCount = $seqLen & 0x7f;
            if ($byteCount === 0 || $offset + $byteCount > $len) {
                throw new \RuntimeException('APNs: malformed DER sequence length');
            }
            $seqLen = 0;
            for ($i = 0; $i < $byteCount; $i++) {
                $seqLen = ($seqLen << 8) | ord($der[$offset + $i]);
            }
            $offset += $byteCount;
        }

        // First INTEGER (r).
        if ($offset >= $len || $der[$offset] !== "\x02") {
            throw new \RuntimeException('APNs: expected INTEGER for r');
        }
        $offset++;
        $rLen = ord($der[$offset]);
        $offset++;
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        // Second INTEGER (s).
        if ($offset >= $len || $der[$offset] !== "\x02") {
            throw new \RuntimeException('APNs: expected INTEGER for s');
        }
        $offset++;
        $sLen = ord($der[$offset]);
        $offset++;
        $s = substr($der, $offset, $sLen);

        // DER INTEGERs are signed: a leading 0x00 may be inserted to disambiguate
        // a positive value whose high bit would otherwise look negative. Strip
        // any leading zero byte that's purely the sign-bit guard.
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        if (strlen($r) > 32 || strlen($s) > 32) {
            throw new \RuntimeException('APNs: ES256 r/s exceeds 32 bytes after DER strip');
        }

        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildApnsBody(PushSendInput $input): array
    {
        $aps = [];
        $narrowed = $input instanceof ApnsPushSendInput ? $input : null;
        $apsOverride = $narrowed?->aps;

        $hasAlert = ($input->title ?? '') !== '' || ($input->body ?? '') !== '' || (is_array($apsOverride) && isset($apsOverride['alert']));
        if ($hasAlert) {
            $alert = [];
            if (is_array($apsOverride) && isset($apsOverride['alert']) && is_array($apsOverride['alert'])) {
                $alert = $apsOverride['alert'];
            }
            if (($input->title ?? '') !== '') {
                $alert['title'] = $input->title;
            }
            if (($input->body ?? '') !== '') {
                $alert['body'] = $input->body;
            }
            $aps['alert'] = $alert;
        }

        if ($narrowed !== null) {
            if ($narrowed->sound !== null) {
                $aps['sound'] = $narrowed->sound;
            }
            if ($narrowed->badge !== null) {
                $aps['badge'] = $narrowed->badge;
            }
        }

        if (is_array($apsOverride)) {
            foreach ($apsOverride as $k => $v) {
                if ($k === 'alert') {
                    continue;
                }
                $aps[$k] = $v;
            }
        }

        $payload = ['aps' => $aps];

        if ($input->data !== null) {
            // Guard the reserved `aps` key so consumer data can never clobber the
            // constructed alert/badge/sound dict (use `_passthrough` to shape aps).
            foreach ($input->data as $k => $v) {
                if ($k === 'aps') {
                    continue;
                }
                $payload[$k] = $v;
            }
        }

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    private function buildApnsHeaders(PushSendInput $input, string $jwt): array
    {
        $narrowed = $input instanceof ApnsPushSendInput ? $input : null;

        $topic = $narrowed->apnsTopic ?? $this->config->bundleId;
        $pushType = $narrowed->apnsPushType
            ?? ((($input->title ?? '') !== '' || ($input->body ?? '') !== '') ? 'alert' : 'background');

        $headers = [
            'authorization'   => 'bearer ' . $jwt,
            'apns-topic'      => $topic,
            'apns-push-type'  => $pushType,
            'content-type'    => 'application/json',
        ];

        if ($narrowed !== null) {
            if ($narrowed->apnsPriority !== null) {
                $headers['apns-priority'] = (string) $narrowed->apnsPriority;
            }
            if ($narrowed->apnsCollapseId !== null) {
                $headers['apns-collapse-id'] = $narrowed->apnsCollapseId;
            }
            if ($narrowed->ttl !== null) {
                $headers['apns-expiration'] = (string) (time() + $narrowed->ttl);
            } elseif ($narrowed->apnsExpiration !== null) {
                $headers['apns-expiration'] = (string) $narrowed->apnsExpiration;
            }
        }

        return $headers;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $reason = '';
        if (is_array($body) && isset($body['reason']) && is_string($body['reason'])) {
            $reason = $body['reason'];
        }

        $message = $reason !== '' ? $reason : "APNs HTTP {$statusCode}";

        $providerCode = $this->classifyError($statusCode, $reason);

        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $providerMessage = $parsedSeconds !== null
            ? "{$message} (Retry-After: {$parsedSeconds} seconds)"
            : $message;

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

    private function classifyError(int $statusCode, string $reason): ProviderCode
    {
        if ($statusCode === 400) {
            return $reason === 'BadDeviceToken'
                ? ProviderCode::InvalidRecipient
                : ProviderCode::InvalidRequest;
        }
        if ($statusCode === 403) {
            if ($reason === 'BadDeviceToken') {
                return ProviderCode::InvalidRecipient;
            }
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 410) {
            return ProviderCode::InvalidRecipient;
        }
        if ($statusCode === 413) {
            return ProviderCode::InvalidRequest;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode === 500 || $statusCode === 503) {
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
     * Per casing-locality: outlier crypto helper stays private on the connector.
     */
    private static function base64UrlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
