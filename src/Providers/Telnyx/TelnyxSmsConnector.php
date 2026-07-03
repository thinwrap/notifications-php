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

    /** @var list<string> */
    private const INVALID_RECIPIENT_CODES = [
        'to_number_invalid',
        'messaging_profile_id_not_found',
        'no_to_number_provided',
    ];

    public function __construct(
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
        if ($merged['query'] !== []) {
            $url .= '?' . http_build_query($merged['query']);
        }

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
        if (
            is_array($body)
            && isset($body['errors'])
            && is_array($body['errors'])
            && isset($body['errors'][0])
            && is_array($body['errors'][0])
        ) {
            $first = $body['errors'][0];
            $errorCode = isset($first['code']) && is_string($first['code']) ? $first['code'] : null;
            $errorDetail = isset($first['detail']) && is_string($first['detail'])
                ? $first['detail']
                : (isset($first['title']) && is_string($first['title']) ? $first['title'] : null);
        }

        $providerCode = match (true) {
            $statusCode === 401 || $statusCode === 403           => ProviderCode::AuthFailed,
            $statusCode === 429                                  => ProviderCode::RateLimited,
            $statusCode >= 500                                   => ProviderCode::ProviderUnavailable,
            // Only a recipient-specific vendor code yields InvalidRecipient; a
            // bare 422 is generic request-validation and falls to InvalidRequest.
            $errorCode !== null && in_array($errorCode, self::INVALID_RECIPIENT_CODES, true)
                                                                 => ProviderCode::InvalidRecipient,
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
