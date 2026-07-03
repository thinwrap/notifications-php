<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Infobip;

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
 * Infobip SMS connector — POSTs JSON to per-account
 * `https://<baseUrl>/sms/2/text/advanced` with `Authorization: App <apiKey>`
 * auth (custom scheme — neither Bearer nor Basic).
 *
 * Mirrors the TS connector. Infobip's v2 advanced endpoint takes a
 * `messages: [...]` array even for one message; each message has
 * `destinations: [{ to }]`. The connector wraps a single message + single
 * destination automatically. Camel-case wire keys (`callbackData`, `notifyUrl`,
 * `validityPeriod`, `bulkId`, `sendingDateTime`, `messageId`) written
 * explicitly per casing-locality.
 */
final class InfobipSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly InfobipConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'infobip';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(SmsSendInput $input): SmsSendResult
    {
        $from = $input->from ?? $this->config->from;
        if ($from === null || $from === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Infobip requires `from` in either input.from or config.from.',
                statusCode: 400,
            );
        }

        $messageEntry = [
            'from'         => $from,
            'destinations' => [['to' => $input->to]],
            'text'         => $input->body,
        ];

        if ($input instanceof InfobipNarrowedInput) {
            if ($input->callbackData !== null) {
                $messageEntry['callbackData'] = $input->callbackData;
            }
            if ($input->notifyUrl !== null) {
                $messageEntry['notifyUrl'] = $input->notifyUrl;
            }
            if ($input->notifyContentType !== null) {
                $messageEntry['notifyContentType'] = $input->notifyContentType;
            }
            if ($input->validityPeriod !== null) {
                $messageEntry['validityPeriod'] = $input->validityPeriod;
            }
            if ($input->validityPeriodTimeUnit !== null) {
                $messageEntry['validityPeriodTimeUnit'] = $input->validityPeriodTimeUnit;
            }
            if ($input->flash !== null) {
                $messageEntry['flash'] = $input->flash;
            }
            if ($input->language !== null) {
                $messageEntry['language'] = $input->language;
            }
            if ($input->transliteration !== null) {
                $messageEntry['transliteration'] = $input->transliteration;
            }
        }

        $body = ['messages' => [$messageEntry]];

        if ($input instanceof InfobipNarrowedInput) {
            if ($input->bulkId !== null) {
                $body['bulkId'] = $input->bulkId;
            }
            if ($input->scheduleSettings !== null && isset($input->scheduleSettings['sendAt'])) {
                $body['sendingDateTime'] = $input->scheduleSettings['sendAt'];
            }
        }

        $headers = [
            'Authorization' => 'App ' . $this->config->apiKey,
            'Accept'        => 'application/json',
        ];

        $merged = Passthrough::merge(
            ['body' => $body, 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $url = "https://{$this->config->baseUrl}/sms/2/text/advanced";
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
            && isset($parsed['messages'])
            && is_array($parsed['messages'])
            && isset($parsed['messages'][0])
            && is_array($parsed['messages'][0])
            && isset($parsed['messages'][0]['messageId'])
            && is_string($parsed['messages'][0]['messageId'])
        ) {
            $messageId = $parsed['messages'][0]['messageId'];
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

        $text = null;
        $code = null;
        if (
            is_array($body)
            && isset($body['requestError'])
            && is_array($body['requestError'])
            && isset($body['requestError']['serviceException'])
            && is_array($body['requestError']['serviceException'])
        ) {
            $svc = $body['requestError']['serviceException'];
            $text = isset($svc['text']) && is_string($svc['text']) ? $svc['text'] : null;
            $code = isset($svc['messageId']) && is_string($svc['messageId']) ? $svc['messageId'] : null;
        }

        $providerCode = match (true) {
            $statusCode === 401 || $statusCode === 403 => ProviderCode::AuthFailed,
            $statusCode === 429                        => ProviderCode::RateLimited,
            $statusCode >= 500                         => ProviderCode::ProviderUnavailable,
            $statusCode >= 400                         => ProviderCode::InvalidRequest,
            default                                    => ProviderCode::Unknown,
        };

        $baseMessage = $text ?? "Infobip HTTP {$statusCode}" . ($code !== null ? " {$code}" : '');
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
