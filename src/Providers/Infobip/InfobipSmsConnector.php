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
 * `validityPeriod`, `bulkId`, `sendAt`, `messageId`) written explicitly per
 * casing-locality.
 *
 * Infobip accepts a send with HTTP 200 even when it rejects the message up
 * front; the verdict is `messages[0].status` (group 5 / `REJECTED`), so a
 * 2xx is only a success once that status is checked.
 */
final class InfobipSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    public readonly string $id;

    public function __construct(
        #[\SensitiveParameter]
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
        $this->assertBaseUrlIsAHostname();
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
                $messageEntry['validityPeriod'] = self::validityPeriodMinutes(
                    $input->validityPeriod,
                    $input->validityPeriodTimeUnit,
                );
            }
            if ($input->flash !== null) {
                $messageEntry['flash'] = $input->flash;
            }
            if ($input->language !== null) {
                $messageEntry['language'] = ['languageCode' => $input->language];
            }
            if ($input->transliteration !== null) {
                $messageEntry['transliteration'] = $input->transliteration;
            }
            // v2 schedules per message (`messages[].sendAt`); there is no
            // request-level send time.
            if ($input->scheduleSettings !== null && isset($input->scheduleSettings['sendAt'])) {
                $messageEntry['sendAt'] = $input->scheduleSettings['sendAt'];
            }
        }

        $body = ['messages' => [$messageEntry]];

        if ($input instanceof InfobipNarrowedInput) {
            if ($input->bulkId !== null) {
                $body['bulkId'] = $input->bulkId;
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

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        /** @var array<string,mixed>|null $parsed */
        $parsed = $this->decodeJson($response);

        $rejection = $this->mapRejectedStatus($parsed, $status);
        if ($rejection !== null) {
            throw $rejection;
        }

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
     * `validityPeriod` on `/sms/2/text/advanced` is an integer number of
     * minutes (v2 has no `validityPeriodTimeUnit`), so another unit is
     * converted here, rounding up so the message never expires earlier than
     * requested.
     */
    private static function validityPeriodMinutes(int $period, ?string $unit): int
    {
        return match (strtoupper($unit ?? 'MINUTES')) {
            'MINUTES' => $period,
            'SECONDS' => intdiv($period + 59, 60),
            'HOURS'   => $period * 60,
            default   => throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Infobip validityPeriodTimeUnit must be SECONDS, MINUTES or HOURS; got '{$unit}'.",
                statusCode: 400,
            ),
        };
    }

    /**
     * Status group 5 (`REJECTED`) means Infobip or the operator refused the
     * message at submission — nothing will be delivered, so the 200 is a
     * failure. Classified by the status name.
     *
     * @param array<string,mixed>|null $parsed
     */
    private function mapRejectedStatus(?array $parsed, int $httpStatus): ?ConnectorError
    {
        $first = is_array($parsed) && isset($parsed['messages']) && is_array($parsed['messages'])
            ? ($parsed['messages'][0] ?? null)
            : null;
        $vendorStatus = is_array($first) && isset($first['status']) && is_array($first['status'])
            ? $first['status']
            : null;
        if ($vendorStatus === null) {
            return null;
        }

        $groupId = $vendorStatus['groupId'] ?? null;
        $groupName = $vendorStatus['groupName'] ?? null;
        if ($groupId !== 5 && $groupName !== 'REJECTED') {
            return null;
        }

        $name = isset($vendorStatus['name']) && is_string($vendorStatus['name']) ? $vendorStatus['name'] : '';
        $description = isset($vendorStatus['description']) && is_string($vendorStatus['description'])
            ? $vendorStatus['description']
            : '';

        $isRecipient = false;
        foreach (['DESTINATION', 'PREFIX', 'MISSING_TO', 'DND', 'NOT_REGISTERED'] as $marker) {
            if (str_contains($name, $marker)) {
                $isRecipient = true;
                break;
            }
        }

        $providerCode = match (true) {
            $isRecipient                           => ProviderCode::InvalidRecipient,
            $name === 'REJECTED_FLOODING_FILTER'   => ProviderCode::RateLimited,
            default                                => ProviderCode::InvalidRequest,
        };

        $label = $name !== '' ? $name : 'REJECTED';

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $description !== '' ? "Infobip {$label}: {$description}" : "Infobip {$label}",
            cause: ['raw' => $parsed, 'retryAfter' => null],
            statusCode: $httpStatus,
        );
    }

    /**
     * `baseUrl` is the account's host (`xyz123.api.infobip.com`) and receives
     * the `Authorization: App <apiKey>` request, so anything beyond a bare DNS
     * hostname — a scheme, path, port, userinfo or trailing slash — could send
     * the key elsewhere. The value is not echoed: a mistyped config can hold
     * the key itself.
     */
    private function assertBaseUrlIsAHostname(): void
    {
        $host = $this->config->baseUrl;
        $valid = $host !== '' && strlen($host) <= 253;
        foreach ($valid ? explode('.', $host) : [] as $label) {
            if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $label) !== 1) {
                $valid = false;
                break;
            }
        }
        if (!$valid) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Infobip baseUrl must be a bare hostname such as xyz123.api.infobip.com (no scheme, path, port or trailing slash)',
                statusCode: 400,
            );
        }
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
