<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Telnyx;

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
 * Telnyx SMS connector — POSTs JSON to `https://api.telnyx.com/v2/messages`
 * with Bearer auth. Snake-case wire keys (`messaging_profile_id`,
 * `webhook_url`, `auto_detect`, `media_urls`) written explicitly per casing-locality.
 *
 * Telnyx wraps successful responses in a JSON:API `{ data: {...} }` envelope;
 * the connector unwraps once for `providerMessageId` and preserves the full
 * envelope in `raw`.
 */
final class TelnyxSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://api.telnyx.com/v2/messages';

    /**
     * Telnyx `errors[].code` (a numeric string) for the synchronous send path:
     * the 403xx messaging API errors plus the platform codes from the
     * api-errors page. The 400xx codes are delivery errors that arrive only in
     * the `message.finalized` webhook, never in this response.
     *
     * @var array<int,ProviderCode>
     */
    private const ERROR_CODES = [
        40300 => ProviderCode::InvalidRecipient,  // Blocked due to STOP message
        40309 => ProviderCode::InvalidRecipient,  // invalid destination region
        40310 => ProviderCode::InvalidRecipient,  // Invalid 'to' address
        10009 => ProviderCode::AuthFailed,        // Authentication failed
        10010 => ProviderCode::AuthFailed,        // Authorization failed
        20001 => ProviderCode::AuthFailed,        // Invalid API Key secret
        20006 => ProviderCode::AuthFailed,        // Expired access token
        40311 => ProviderCode::AuthFailed,        // Invalid messaging profile secret
        40313 => ProviderCode::AuthFailed,        // Missing messaging profile secret
        10011 => ProviderCode::RateLimited,       // Too many requests
        40318 => ProviderCode::RateLimited,       // Message queue full
        40301 => ProviderCode::InvalidRequest,
        40302 => ProviderCode::InvalidRequest,
        40303 => ProviderCode::InvalidRequest,
        40304 => ProviderCode::InvalidRequest,
        40305 => ProviderCode::InvalidRequest,    // Invalid 'from' address
        40306 => ProviderCode::InvalidRequest,
        40307 => ProviderCode::InvalidRequest,
        40308 => ProviderCode::InvalidRequest,
        40314 => ProviderCode::InvalidRequest,    // Messaging disabled on account
        40316 => ProviderCode::InvalidRequest,    // No content provided for message
        40317 => ProviderCode::InvalidRequest,
        40319 => ProviderCode::InvalidRequest,
        40320 => ProviderCode::InvalidRequest,
        40321 => ProviderCode::InvalidRequest,
        40322 => ProviderCode::InvalidRequest,
        40323 => ProviderCode::InvalidRequest,
        40324 => ProviderCode::InvalidRequest,
        40325 => ProviderCode::InvalidRequest,
        40328 => ProviderCode::InvalidRequest,
        40331 => ProviderCode::InvalidRequest,
        40333 => ProviderCode::InvalidRequest,
        10002 => ProviderCode::InvalidRequest,    // Invalid phone number (see /to below)
        10004 => ProviderCode::InvalidRequest,    // Missing required parameter
        10015 => ProviderCode::InvalidRequest,    // Bad Request
        20100 => ProviderCode::InvalidRequest,    // Insufficient Funds
    ];

    public function __construct(
        #[\SensitiveParameter]
        private readonly TelnyxConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'telnyx';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(SmsSendInput $input): SmsSendResult
    {
        $from = $input->from ?? $this->config->from;
        $messagingProfileId = $input instanceof TelnyxNarrowedInput ? $input->messagingProfileId : null;

        if (($from === null || $from === '') && ($messagingProfileId === null || $messagingProfileId === '')) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Telnyx requires `from`, `config.from`, or `messagingProfileId`.',
                statusCode: 400,
            );
        }

        $body = [
            'to'   => $input->to,
            'text' => $input->body,
        ];
        if ($from !== null && $from !== '') {
            $body['from'] = $from;
        }
        if ($input instanceof TelnyxNarrowedInput) {
            if ($input->messagingProfileId !== null) {
                $body['messaging_profile_id'] = $input->messagingProfileId;
            }
            if ($input->webhookUrl !== null) {
                $body['webhook_url'] = $input->webhookUrl;
            }
            if ($input->webhookFailoverUrl !== null) {
                $body['webhook_failover_url'] = $input->webhookFailoverUrl;
            }
            if ($input->useProfileWebhooks !== null) {
                $body['use_profile_webhooks'] = $input->useProfileWebhooks;
            }
            if ($input->type !== null) {
                $body['type'] = $input->type;
            }
            if ($input->autoDetect !== null) {
                $body['auto_detect'] = $input->autoDetect;
            }
            if ($input->mediaUrls !== null && $input->mediaUrls !== []) {
                $body['media_urls'] = $input->mediaUrls;
            }
            if ($input->subject !== null) {
                $body['subject'] = $input->subject;
            }
        }

        $headers = ['Authorization' => 'Bearer ' . $this->config->apiKey];

        $merged = Passthrough::merge(
            ['body' => $body, 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $url = self::ENDPOINT;

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        /** @var array<string,mixed>|null $parsed */
        $parsed = $this->decodeJson($response);
        $messageId = null;
        if (
            is_array($parsed)
            && isset($parsed['data'])
            && is_array($parsed['data'])
            && isset($parsed['data']['id'])
            && is_string($parsed['data']['id'])
        ) {
            $messageId = $parsed['data']['id'];
        }

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

        $errorCode = null;
        $errorDetail = null;
        $pointer = null;
        if (
            is_array($body)
            && isset($body['errors'])
            && is_array($body['errors'])
            && isset($body['errors'][0])
            && is_array($body['errors'][0])
        ) {
            $first = $body['errors'][0];
            $errorCode = isset($first['code']) && (is_string($first['code']) || is_int($first['code']))
                ? (string) $first['code']
                : null;
            $pointer = isset($first['source']) && is_array($first['source']) && isset($first['source']['pointer']) && is_string($first['source']['pointer'])
                ? $first['source']['pointer']
                : null;
            $errorDetail = isset($first['detail']) && is_string($first['detail'])
                ? $first['detail']
                : (isset($first['title']) && is_string($first['title']) ? $first['title'] : null);
        }

        // The vendor code wins; a bare 400/422 is generic request validation.
        // "Invalid phone number" is the recipient's only when it points at /to.
        $providerCode = match (true) {
            $errorCode === '10002' && $pointer === '/to'         => ProviderCode::InvalidRecipient,
            $errorCode !== null && ctype_digit($errorCode) && isset(self::ERROR_CODES[(int) $errorCode])
                                                                 => self::ERROR_CODES[(int) $errorCode],
            $statusCode === 401 || $statusCode === 403           => ProviderCode::AuthFailed,
            $statusCode === 429                                  => ProviderCode::RateLimited,
            $statusCode >= 500                                   => ProviderCode::ProviderUnavailable,
            $statusCode >= 400                                   => ProviderCode::InvalidRequest,
            default                                              => ProviderCode::Unknown,
        };

        $baseMessage = $errorDetail ?? "Telnyx HTTP {$statusCode}";
        $providerMessage = $parsedSeconds !== null
            ? "{$baseMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $baseMessage;

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            cause: [
                'raw'        => $body,
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
