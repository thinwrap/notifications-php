<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Vonage;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Base\BaseConnector;
use Thinwrap\Notifications\Contract\SmsConnectorInterface;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsSendResult;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Util\Passthrough;

/**
 * Vonage (Nexmo) SMS connector — POSTs `application/x-www-form-urlencoded` to
 * `https://rest.nexmo.com/sms/json`. Auth is via FORM FIELDS (`api_key`,
 * `api_secret`) inside the request body, NOT via HTTP headers.
 *
 * Mirrors the TS connector. casing-locality outlier: Vonage emits HTTP 200 even for soft
 * errors; `messages[0].status` is the source of truth. Non-`'0'` status maps
 * via `VONAGE_STATUS_TO_PROVIDER_CODE` to a canonical `ConnectorError`.
 *
 * Wire keys are kebab-case (`client-ref`, `message-class`, `status-report-req`)
 * — written explicitly per casing-locality.
 *
 * Registered under both `vonage` (canonical) and `nexmo` (Novu legacy alias)
 * in the Sms facade by design.
 */
final class VonageSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://rest.nexmo.com/sms/json';

    /**
     * Body-layer status → ProviderCode mapping matching the TS connector.
     * Status `'0'` is success; anything else maps to a canonical failure code.
     *
     * @var array<int,ProviderCode>
     */
    private const STATUS_MAP = [
        '1'  => ProviderCode::RateLimited,         // Throttled
        '2'  => ProviderCode::InvalidRequest,      // Missing params
        '3'  => ProviderCode::InvalidRequest,      // Invalid params
        '4'  => ProviderCode::AuthFailed,          // Invalid credentials
        '5'  => ProviderCode::ProviderUnavailable, // Internal error
        '6'  => ProviderCode::InvalidRequest,      // Invalid message
        '7'  => ProviderCode::InvalidRecipient,    // Number barred
        '8'  => ProviderCode::AuthFailed,          // Partner account barred
        '9'  => ProviderCode::AuthFailed,          // Partner quota exceeded
        '11' => ProviderCode::InvalidRequest,      // Account not enabled for REST
        '12' => ProviderCode::InvalidRequest,      // Message too long
        '13' => ProviderCode::InvalidRequest,      // Communication failed
        '14' => ProviderCode::InvalidRequest,      // Invalid signature
        '15' => ProviderCode::InvalidRequest,      // Invalid sender address
        '16' => ProviderCode::InvalidRequest,      // Invalid TTL
        '19' => ProviderCode::InvalidRequest,      // Facility not allowed
        '20' => ProviderCode::InvalidRequest,      // Invalid message class
        '23' => ProviderCode::InvalidRequest,      // Bad callback
        '29' => ProviderCode::InvalidRecipient,    // Non-whitelisted destination
        '34' => ProviderCode::InvalidRecipient,    // Invalid phone number
    ];

    public function __construct(
        private readonly VonageConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'vonage';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(SmsSendInput $input): SmsSendResult
    {
        $from = $input->from ?? $this->config->from;
        if ($from === null || $from === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Vonage requires `from` in either input.from or config.from.',
                statusCode: 400,
            );
        }

        $form = [
            'api_key'    => $this->config->apiKey,
            'api_secret' => $this->config->apiSecret,
            'from'       => $from,
            'to'         => $input->to,
            'text'       => $input->body,
        ];

        if ($input instanceof VonageNarrowedInput) {
            if ($input->clientRef !== null) {
                $form['client-ref'] = $input->clientRef;
            }
            if ($input->messageClass !== null) {
                $form['message-class'] = (string) $input->messageClass;
            }
            if ($input->type !== null) {
                $form['type'] = $input->type;
            }
            if ($input->statusReportReq !== null) {
                $form['status-report-req'] = (string) $input->statusReportReq;
            }
            if ($input->ttl !== null) {
                $form['ttl'] = (string) $input->ttl;
            }
            if ($input->callback !== null) {
                $form['callback'] = $input->callback;
            }
        }

        $merged = Passthrough::merge(
            ['body' => $form, 'headers' => [], 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        // Coerce all form values to scalars/strings for sendPostForm.
        $formOut = [];
        foreach ($mergedBody as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_array($v)) {
                continue; // form-encoded: lists are out-of-scope at Vonage call site
            }
            if (is_bool($v)) {
                $formOut[$k] = $v ? 'true' : 'false';
            } elseif (is_scalar($v)) {
                $formOut[$k] = (string) $v;
            }
        }

        $url = self::ENDPOINT;
        if ($merged['query'] !== []) {
            $url .= '?' . http_build_query($merged['query']);
        }

        $response = $this->sendPostForm($url, $formOut, $mergedHeaders);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $errBody = (string) $response->getBody();
            throw $this->mapVendorError($status, $errBody === '' ? null : ['raw' => $errBody], $response);
        }

        /** @var array<string,mixed>|null $parsed */
        $parsed = $this->decodeJson($response);
        $message = is_array($parsed) && isset($parsed['messages']) && is_array($parsed['messages']) && isset($parsed['messages'][0]) && is_array($parsed['messages'][0])
            ? $parsed['messages'][0]
            : null;

        if ($message === null) {
            throw new ConnectorError(
                providerCode: ProviderCode::Unknown,
                providerMessage: 'Vonage response missing `messages[0]`.',
                cause: ['raw' => $parsed, 'retryAfter' => null],
                statusCode: $status,
            );
        }

        $bodyStatus = isset($message['status']) ? (string) $message['status'] : 'unknown';
        if ($bodyStatus !== '0') {
            $providerCode = self::STATUS_MAP[$bodyStatus] ?? ProviderCode::Unknown;
            $errText = isset($message['error-text']) && is_string($message['error-text'])
                ? $message['error-text']
                : "Vonage status {$bodyStatus}";
            throw new ConnectorError(
                providerCode: $providerCode,
                providerMessage: $errText,
                cause: ['raw' => $parsed, 'retryAfter' => null],
                statusCode: $status,
            );
        }

        $messageId = isset($message['message-id']) && is_string($message['message-id'])
            ? $message['message-id']
            : null;

        return new SmsSendResult(
            success: true,
            status: SmsStatus::Sent,
            providerMessageId: $messageId,
            raw: $parsed,
        );
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;

        $providerCode = match (true) {
            $statusCode === 401 || $statusCode === 403 => ProviderCode::AuthFailed,
            $statusCode === 429                        => ProviderCode::RateLimited,
            $statusCode >= 500                         => ProviderCode::ProviderUnavailable,
            $statusCode >= 400                         => ProviderCode::InvalidRequest,
            default                                    => ProviderCode::Unknown,
        };

        $rawBody = is_array($body) && isset($body['raw']) && is_string($body['raw']) ? $body['raw'] : null;
        $baseMessage = $rawBody ?? "Vonage HTTP {$statusCode}";
        $providerMessage = $parsedSeconds !== null
            ? "{$baseMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $baseMessage;

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            cause: [
                'raw'       => $rawBody,
                'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
                'retryAfterSeconds' => $parsedSeconds,
            ],
            statusCode: $statusCode,
        );
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
}
