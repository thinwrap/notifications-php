<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Messagebird;

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
 * MessageBird SMS connector — POSTs JSON to
 * `https://rest.messagebird.com/messages` with
 * `Authorization: AccessKey <accessKey>` (custom auth scheme).
 *
 * Mirrors the TS connector. casing-locality outlier mapping: `dataCoding` narrowed-input
 * camelCase → wire key `datacoding` (lowercased-flat).
 *
 * `recipients` is `string[]` even for single recipient — wrapped automatically.
 */
final class MessagebirdSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://rest.messagebird.com/messages';

    public function __construct(
        private readonly MessagebirdConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'messagebird';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(SmsSendInput $input): SmsSendResult
    {
        $originator = $input->from ?? $this->config->from;
        if ($originator === null || $originator === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'MessageBird requires `from` (originator) in either input.from or config.from.',
                statusCode: 400,
            );
        }

        $body = [
            'originator' => $originator,
            'recipients' => [$input->to],
            'body'       => $input->body,
        ];

        if ($input instanceof MessagebirdNarrowedInput) {
            if ($input->type !== null) {
                $body['type'] = $input->type;
            }
            if ($input->reference !== null) {
                $body['reference'] = $input->reference;
            }
            if ($input->validity !== null) {
                $body['validity'] = $input->validity;
            }
            if ($input->gateway !== null) {
                $body['gateway'] = $input->gateway;
            }
            if ($input->typeDetails !== null) {
                $body['typeDetails'] = $input->typeDetails;
            }
            // casing-locality outlier: camelCase narrowed → lowercased-flat wire.
            if ($input->dataCoding !== null) {
                $body['datacoding'] = $input->dataCoding;
            }
            if ($input->mclass !== null) {
                $body['mclass'] = $input->mclass;
            }
            if ($input->scheduledDatetime !== null) {
                $body['scheduledDatetime'] = $input->scheduledDatetime;
            }
        }

        $headers = ['Authorization' => 'AccessKey ' . $this->config->accessKey];

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
        $messageId = is_array($parsed) && isset($parsed['id']) && is_string($parsed['id'])
            ? $parsed['id']
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

        $description = null;
        if (
            is_array($body)
            && isset($body['errors'])
            && is_array($body['errors'])
            && isset($body['errors'][0])
            && is_array($body['errors'][0])
            && isset($body['errors'][0]['description'])
            && is_string($body['errors'][0]['description'])
        ) {
            $description = $body['errors'][0]['description'];
        }

        $providerCode = match (true) {
            $statusCode === 401 || $statusCode === 403 => ProviderCode::AuthFailed,
            $statusCode === 429                        => ProviderCode::RateLimited,
            $statusCode >= 500                         => ProviderCode::ProviderUnavailable,
            $statusCode >= 400                         => ProviderCode::InvalidRequest,
            default                                    => ProviderCode::Unknown,
        };

        $baseMessage = $description ?? "MessageBird HTTP {$statusCode}";
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
