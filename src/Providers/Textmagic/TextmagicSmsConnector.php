<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Textmagic;

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
 * Textmagic SMS connector — POSTs `application/x-www-form-urlencoded` to
 * `https://rest.textmagic.com/api/v2/messages` with TWO-HEADER auth:
 * `X-TM-Username` + `X-TM-Key` (not Basic, not Bearer).
 *
 * Mirrors the TS connector. Wire keys are camelCase, written explicitly per
 * casing-locality. The recipient field is `phones` on the wire (Textmagic accepts a
 * comma-separated multi-recipient on this field); single-recipient v1.0
 * baseline maps `input.to` → `phones` verbatim. Booleans encode as `'1'`/`'0'`.
 */
final class TextmagicSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://rest.textmagic.com/api/v2/messages';

    public function __construct(
        private readonly TextmagicConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'textmagic';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(SmsSendInput $input): SmsSendResult
    {
        $from = $input->from ?? $this->config->from;

        /** @var array<string,scalar|list<scalar>|null> $form */
        $form = [
            'text'   => $input->body,
            'phones' => $input->to,
        ];
        if ($from !== null && $from !== '') {
            $form['from'] = $from;
        }

        if ($input instanceof TextmagicNarrowedInput) {
            if ($input->templateId !== null) {
                $form['templateId'] = (string) $input->templateId;
            }
            if ($input->sendingTime !== null) {
                $form['sendingTime'] = (string) $input->sendingTime;
            }
            if ($input->tz !== null) {
                $form['tz'] = $input->tz;
            }
            if ($input->partsCount !== null) {
                $form['partsCount'] = (string) $input->partsCount;
            }
            if ($input->referenceId !== null) {
                $form['referenceId'] = (string) $input->referenceId;
            }
            if ($input->rrule !== null) {
                $form['rrule'] = $input->rrule;
            }
            if ($input->cutExtra !== null) {
                $form['cutExtra'] = $input->cutExtra ? '1' : '0';
            }
        }

        $headers = [
            'X-TM-Username' => $this->config->username,
            'X-TM-Key'      => $this->config->apiKey,
        ];

        $merged = Passthrough::merge(
            ['body' => [], 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );
        /** @var array<string,mixed> $passthroughBody */
        $passthroughBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        foreach ($passthroughBody as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_bool($v)) {
                $form[$k] = $v ? '1' : '0';
            } elseif (is_scalar($v)) {
                $form[$k] = $v;
            }
        }

        $url = self::ENDPOINT;
        if ($merged['query'] !== []) {
            $url .= '?' . http_build_query($merged['query']);
        }

        $response = $this->sendPostForm($url, $form, $mergedHeaders);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        /** @var array<string,mixed>|null $parsed */
        $parsed = $this->decodeJson($response);
        $messageId = null;
        if (is_array($parsed)) {
            if (isset($parsed['id']) && (is_int($parsed['id']) || is_string($parsed['id']))) {
                $messageId = (string) $parsed['id'];
            } elseif (isset($parsed['messageId']) && (is_int($parsed['messageId']) || is_string($parsed['messageId']))) {
                $messageId = (string) $parsed['messageId'];
            }
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

        $message = is_array($body) && isset($body['message']) && is_string($body['message'])
            ? $body['message']
            : null;
        $code = null;
        if (is_array($body) && isset($body['code'])) {
            if (is_string($body['code']) || is_int($body['code'])) {
                $code = (string) $body['code'];
            }
        }

        // A 422 is generic request-validation; only escalate to InvalidRecipient
        // when the error message names a recipient/phone problem, otherwise
        // InvalidRequest. (Textmagic exposes no dedicated recipient error code.)
        $recipientSignal = $message !== null
            && preg_match('/\b(phone|phones|recipient|number)\b/i', $message) === 1;

        $providerCode = match (true) {
            $statusCode === 401 || $statusCode === 403 => ProviderCode::AuthFailed,
            $statusCode === 429                        => ProviderCode::RateLimited,
            $statusCode === 422 && $recipientSignal    => ProviderCode::InvalidRecipient,
            $statusCode >= 500                         => ProviderCode::ProviderUnavailable,
            $statusCode >= 400                         => ProviderCode::InvalidRequest,
            default                                    => ProviderCode::Unknown,
        };

        $baseMessage = $message ?? "Textmagic HTTP {$statusCode}" . ($code !== null ? " {$code}" : '');
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
