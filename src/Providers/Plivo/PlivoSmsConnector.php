<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Plivo;

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
 * Plivo SMS connector — POSTs JSON to
 * `https://api.plivo.com/v1/Account/<authId>/Message/` (trailing slash
 * required) with HTTP Basic auth.
 *
 * Mirrors the TS connector. Snake-case wire keys (`src`, `dst`, `text`, `dlt_*`,
 * `powerpack_uuid`, `template_id`) written explicitly per casing-locality.
 *
 * `message_uuid` is documented as `string[]`; Thinwrap reports
 * `providerMessageId = raw.message_uuid[0]`, matching the TS connector.
 * Consumers needing all segment UUIDs read `result.raw.message_uuid`.
 */
final class PlivoSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly PlivoConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'plivo';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(SmsSendInput $input): SmsSendResult
    {
        $src = $input->from ?? $this->config->from;
        $powerpackUuid = $input instanceof PlivoNarrowedInput ? $input->powerpackUuid : null;

        if (($src === null || $src === '') && ($powerpackUuid === null || $powerpackUuid === '')) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Plivo requires `from`, `config.from`, or `powerpackUuid`.',
                statusCode: 400,
            );
        }

        $body = [
            'dst'  => $input->to,
            'text' => $input->body,
        ];
        if ($src !== null && $src !== '') {
            $body['src'] = $src;
        }
        if ($input instanceof PlivoNarrowedInput) {
            if ($input->powerpackUuid !== null) {
                $body['powerpack_uuid'] = $input->powerpackUuid;
            }
            if ($input->url !== null) {
                $body['url'] = $input->url;
            }
            if ($input->method !== null) {
                $body['method'] = $input->method;
            }
            if ($input->log !== null) {
                $body['log'] = $input->log;
            }
            if ($input->trackable !== null) {
                $body['trackable'] = $input->trackable;
            }
            if ($input->dltEntityId !== null) {
                $body['dlt_entity_id'] = $input->dltEntityId;
            }
            if ($input->dltTemplateId !== null) {
                $body['dlt_template_id'] = $input->dltTemplateId;
            }
            if ($input->dltTemplateCategory !== null) {
                $body['dlt_template_category'] = $input->dltTemplateCategory;
            }
            if ($input->templateId !== null) {
                $body['template_id'] = $input->templateId;
            }
        }

        $authHeader = 'Basic ' . base64_encode("{$this->config->authId}:{$this->config->authToken}");
        $headers = ['Authorization' => $authHeader];

        $merged = Passthrough::merge(
            ['body' => $body, 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $url = "https://api.plivo.com/v1/Account/{$this->config->authId}/Message/";

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
            && isset($parsed['message_uuid'])
            && is_array($parsed['message_uuid'])
            && isset($parsed['message_uuid'][0])
            && is_string($parsed['message_uuid'][0])
        ) {
            $messageId = $parsed['message_uuid'][0];
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

        $providerCode = match (true) {
            $statusCode === 401 || $statusCode === 403 => ProviderCode::AuthFailed,
            $statusCode === 429                => ProviderCode::RateLimited,
            $statusCode >= 500                 => ProviderCode::ProviderUnavailable,
            $statusCode >= 400                 => ProviderCode::InvalidRequest,
            default                            => ProviderCode::Unknown,
        };

        $baseMessage = "Plivo HTTP {$statusCode}";
        if (is_array($body) && isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
            $baseMessage = $body['error'];
        }
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
