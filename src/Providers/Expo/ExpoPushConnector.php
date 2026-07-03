<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Expo;

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
 * Expo push connector. Mirrors TS `ExpoPushConnector`
 * (notifications-ts/src/providers/expo/expo.connector.ts).
 *
 * Expo wire is camelCase native — keys are written explicitly in the array
 * literal per casing-locality, no casing transform.
 *
 * Expo emits HTTP 200 even for soft errors; `data.status === 'error'` is
 * routed through `mapTicketError()` after the HTTP layer.
 */
final class ExpoPushConnector extends BaseConnector implements PushConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /** @var array<string, ProviderCode> */
    private const TICKET_ERROR_MAP = [
        'DeviceNotRegistered'  => ProviderCode::InvalidRecipient,
        'MessageTooBig'        => ProviderCode::InvalidRequest,
        'MessageRateExceeded'  => ProviderCode::RateLimited,
        'MismatchSenderId'     => ProviderCode::AuthFailed,
        'InvalidCredentials'   => ProviderCode::AuthFailed,
    ];

    public function __construct(
        private readonly ExpoConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'expo';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(PushSendInput $input): PushSendResult
    {
        $connectorBody = ['to' => $input->to];
        if (($input->title ?? '') !== '') {
            $connectorBody['title'] = $input->title;
        }
        if (($input->body ?? '') !== '') {
            $connectorBody['body'] = $input->body;
        }
        if ($input->data !== null) {
            $connectorBody['data'] = $input->data;
        }

        if ($input instanceof ExpoPushSendInput) {
            if ($input->sound !== null) {
                $connectorBody['sound']             = $input->sound;
            }
            if ($input->badge !== null) {
                $connectorBody['badge']             = $input->badge;
            }
            if ($input->ttl !== null) {
                $connectorBody['ttl']               = $input->ttl;
            }
            if ($input->priority !== null) {
                $connectorBody['priority']          = $input->priority;
            }
            if ($input->channelId !== null) {
                $connectorBody['channelId']         = $input->channelId;
            }
            if ($input->categoryId !== null) {
                $connectorBody['categoryId']        = $input->categoryId;
            }
            if ($input->mutableContent !== null) {
                $connectorBody['mutableContent']    = $input->mutableContent;
            }
            if ($input->subtitle !== null) {
                $connectorBody['subtitle']          = $input->subtitle;
            }
            if ($input->interruptionLevel !== null) {
                $connectorBody['interruptionLevel'] = $input->interruptionLevel;
            }
            if ($input->displayInForeground !== null) {
                $connectorBody['_displayInForeground'] = $input->displayInForeground;
            }
        }

        $connectorHeaders = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
        if ($this->config->accessToken !== null) {
            $connectorHeaders['Authorization'] = 'Bearer ' . $this->config->accessToken;
        }

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => $connectorHeaders, 'query' => []],
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
            /** @var array<string,mixed>|null $errBody */
            $errBody = $this->decodeJson($response);
            throw $this->mapVendorError($status, $errBody, $response);
        }

        /** @var array<string,mixed>|null $raw */
        $raw = $this->decodeJson($response);
        $ticket = null;
        if (is_array($raw) && isset($raw['data'])) {
            $data = $raw['data'];
            if (is_array($data)) {
                if (isset($data[0]) && is_array($data[0])) {
                    $ticket = $data[0];
                } elseif (!array_is_list($data)) {
                    $ticket = $data;
                }
            }
        }

        if (!is_array($ticket)) {
            throw new ConnectorError(
                providerCode: ProviderCode::Unknown,
                providerMessage: 'Expo response missing `data` ticket.',
                cause: ['raw' => $raw, 'retryAfter' => null],
                statusCode: $status,
            );
        }

        if (($ticket['status'] ?? null) === 'error') {
            throw $this->mapTicketError($ticket, $raw, $status);
        }

        $providerMessageId = null;
        if (isset($ticket['id']) && is_string($ticket['id'])) {
            $providerMessageId = $ticket['id'];
        }

        return new PushSendResult(
            success: true,
            status: PushStatus::Sent,
            providerMessageId: $providerMessageId,
            raw: $raw,
        );
    }

    /**
     * @param array<string,mixed> $ticket
     * @param array<string,mixed>|null $raw
     */
    private function mapTicketError(array $ticket, ?array $raw, int $httpStatus): ConnectorError
    {
        $errorCode = null;
        if (isset($ticket['details']) && is_array($ticket['details']) && isset($ticket['details']['error']) && is_string($ticket['details']['error'])) {
            $errorCode = $ticket['details']['error'];
        }
        $providerCode = $errorCode !== null && isset(self::TICKET_ERROR_MAP[$errorCode])
            ? self::TICKET_ERROR_MAP[$errorCode]
            : ProviderCode::Unknown;

        $message = isset($ticket['message']) && is_string($ticket['message'])
            ? $ticket['message']
            : 'Expo ticket error: ' . ($errorCode ?? 'unknown');

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $message,
            cause: ['raw' => $raw, 'retryAfter' => null],
            statusCode: $httpStatus,
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
            $statusCode === 401 => ProviderCode::AuthFailed,
            $statusCode === 429 => ProviderCode::RateLimited,
            $statusCode >= 500  => ProviderCode::ProviderUnavailable,
            $statusCode >= 400  => ProviderCode::InvalidRequest,
            default             => ProviderCode::Unknown,
        };

        $baseMessage = "Expo HTTP {$statusCode}";
        if (is_array($body) && isset($body['errors']) && is_array($body['errors']) && isset($body['errors'][0]['message']) && is_string($body['errors'][0]['message'])) {
            $baseMessage = $body['errors'][0]['message'];
        }
        $providerMessage = $parsedSeconds !== null
            ? "{$baseMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $baseMessage;

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
