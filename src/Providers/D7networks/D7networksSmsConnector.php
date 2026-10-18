<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\D7networks;

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
 * D7 Networks SMS connector — POSTs JSON to
 * `https://api.d7networks.com/messages/v1/send` with Bearer auth.
 *
 * Mirrors the TS connector
 * (`notifications-ts/src/providers/d7networks/d7networks.connector.ts`).
 *
 * D7's wire shape is the most nested of any SMS provider (casing-locality outlier):
 * per-message fields live inside `messages[]`, global fields live inside
 * `message_globals`. The connector silently allocates each narrowed-input
 * field to the correct nesting level. Snake-case wire keys (`msg_type`,
 * `data_coding`, `schedule_time`, `validity_period`, `report_url`,
 * `message_globals`) are written directly in the array literal per casing-locality —
 * no shared casing transform.
 *
 * `originator` precedence: `$input->originator` (preferred, matches D7's
 * vocabulary) → `$input->from` (matches Thinwrap baseline) → `$config->from`.
 * If none present, throws `ConnectorError(InvalidRequest)` BEFORE the HTTP
 * call.
 */
final class D7networksSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://api.d7networks.com/messages/v1/send';

    public function __construct(
        #[\SensitiveParameter]
        private readonly D7networksConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'd7networks';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(SmsSendInput $input): SmsSendResult
    {
        $narrowed = $input instanceof D7networksNarrowedInput ? $input : null;

        $originator = $narrowed->originator ?? $input->from ?? $this->config->from;
        if ($originator === null || $originator === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'D7 Networks requires `originator` or `from` in input, or `config.from`.',
                statusCode: 400,
            );
        }

        $messageEntry = [
            'channel'    => 'sms',
            'recipients' => [$input->to],
            'content'    => $input->body,
            'msg_type'   => $narrowed->msgType ?? 'text',
        ];
        if ($narrowed?->dataCoding !== null) {
            $messageEntry['data_coding'] = $narrowed->dataCoding;
        }

        $messageGlobals = ['originator' => $originator];
        if ($narrowed?->tag !== null) {
            $messageGlobals['tag'] = $narrowed->tag;
        }
        if ($narrowed?->scheduleTime !== null) {
            $messageGlobals['schedule_time'] = $narrowed->scheduleTime;
        }
        if ($narrowed?->validityPeriod !== null) {
            $messageGlobals['validity_period'] = $narrowed->validityPeriod;
        }
        if ($narrowed?->reportUrl !== null) {
            $messageGlobals['report_url'] = $narrowed->reportUrl;
        }

        $body = [
            'messages'        => [$messageEntry],
            'message_globals' => $messageGlobals,
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $this->config->apiToken,
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

        $url = self::ENDPOINT;

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        /** @var array<string,mixed>|null $parsed */
        $parsed = $this->decodeJson($response);
        $messageId = $this->extractRequestId($parsed);

        return new SmsSendResult(
            success: true,
            status: SmsStatus::Sent,
            providerMessageId: $messageId,
            raw: $parsed,
        );
    }

    /**
     * D7 documents `request_id` at top-level; their redoc also shows the nested
     * `data.request_id` shape — accept both defensively.
     *
     * @param array<string,mixed>|null $parsed
     */
    private function extractRequestId(?array $parsed): ?string
    {
        if (!is_array($parsed)) {
            return null;
        }
        if (
            isset($parsed['data'])
            && is_array($parsed['data'])
            && isset($parsed['data']['request_id'])
            && is_string($parsed['data']['request_id'])
        ) {
            return $parsed['data']['request_id'];
        }
        if (isset($parsed['request_id']) && is_string($parsed['request_id'])) {
            return $parsed['request_id'];
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;

        $detail = is_array($body) && isset($body['detail']) && is_string($body['detail']) ? $body['detail'] : null;
        $code = is_array($body) && isset($body['code']) && is_string($body['code']) ? $body['code'] : null;

        $providerCode = match (true) {
            $statusCode === 401 || $statusCode === 403 => ProviderCode::AuthFailed,
            $statusCode === 429                        => ProviderCode::RateLimited,
            $statusCode >= 500                         => ProviderCode::ProviderUnavailable,
            $statusCode >= 400                         => ProviderCode::InvalidRequest,
            default                                    => ProviderCode::Unknown,
        };

        $baseMessage = $detail ?? trim("D7 Networks HTTP {$statusCode}" . ($code !== null ? " {$code}" : ''));
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
