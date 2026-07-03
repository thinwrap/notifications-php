<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sinch;

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
 * Sinch SMS connector — POSTs JSON to the regional batches endpoint
 * `https://<region>.sms.api.sinch.com/xms/v1/<servicePlanId>/batches`.
 *
 * Mirrors the TS connector. Sinch's batches API expects `to: string[]` even for
 * single-recipient sends; the connector wraps `[input.to]` automatically.
 * Multi-recipient batches are out of the v1.0 baseline — consumers
 * override via `_passthrough.body.to`.
 */
final class SinchSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly SinchConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'sinch';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(SmsSendInput $input): SmsSendResult
    {
        $from = $input->from ?? $this->config->from;
        if ($from === null || $from === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Sinch requires `from` in either input.from or config.from.',
                statusCode: 400,
            );
        }

        $body = [
            'from' => $from,
            'to'   => [$input->to],
            'body' => $input->body,
        ];

        if ($input instanceof SinchNarrowedInput) {
            if ($input->type !== null) {
                $body['type'] = $input->type;
            }
            if ($input->parameters !== null) {
                $body['parameters'] = $input->parameters;
            }
            if ($input->deliveryReport !== null) {
                $body['delivery_report'] = $input->deliveryReport;
            }
            if ($input->sendAt !== null) {
                $body['send_at'] = $input->sendAt;
            }
            if ($input->expireAt !== null) {
                $body['expire_at'] = $input->expireAt;
            }
            if ($input->callbackUrl !== null) {
                $body['callback_url'] = $input->callbackUrl;
            }
            if ($input->clientReference !== null) {
                $body['client_reference'] = $input->clientReference;
            }
            if ($input->feedbackEnabled !== null) {
                $body['feedback_enabled'] = $input->feedbackEnabled;
            }
            if ($input->flashMessage !== null) {
                $body['flash_message'] = $input->flashMessage;
            }
        }

        $headers = ['Authorization' => 'Bearer ' . $this->config->apiToken];

        $merged = Passthrough::merge(
            ['body' => $body, 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $regionValue = $this->config->region->value ?? 'us';
        $url = "https://{$regionValue}.sms.api.sinch.com/xms/v1/{$this->config->servicePlanId}/batches";
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

        $text = is_array($body) && isset($body['text']) && is_string($body['text']) ? $body['text'] : null;
        $code = is_array($body) && isset($body['code']) && is_string($body['code']) ? $body['code'] : null;

        $providerCode = match (true) {
            $statusCode === 401 || $statusCode === 403           => ProviderCode::AuthFailed,
            $statusCode === 429                                  => ProviderCode::RateLimited,
            $statusCode >= 500                                   => ProviderCode::ProviderUnavailable,
            $code === 'invalid_destination'
                || $code === 'invalid_recipient'                 => ProviderCode::InvalidRecipient,
            $statusCode >= 400                                   => ProviderCode::InvalidRequest,
            default                                              => ProviderCode::Unknown,
        };

        $baseMessage = $text ?? "Sinch HTTP {$statusCode}";
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
