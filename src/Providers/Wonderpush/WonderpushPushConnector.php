<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Wonderpush;

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
 * WonderPush push connector. Mirrors TS `WonderPushPushConnector`
 * (notifications-ts/src/providers/wonderpush/wonderpush.connector.ts).
 *
 * Auth: `Authorization: Bearer <accessToken>` (no query-param auth).
 * Wire shape: WonderPush Management API `/v1/deliveries`.
 */
final class WonderpushPushConnector extends BaseConnector implements PushConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://management-api.wonderpush.com/v1/deliveries';

    public function __construct(
        private readonly WonderpushConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'wonderpush';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(PushSendInput $input): PushSendResult
    {
        $connectorBody = $this->buildRequestBody($input);

        $connectorHeaders = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->accessToken,
        ];

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
        $providerMessageId = null;
        if (is_array($raw)) {
            if (isset($raw['id']) && is_string($raw['id'])) {
                $providerMessageId = $raw['id'];
            } elseif (isset($raw['notificationId']) && is_string($raw['notificationId'])) {
                $providerMessageId = $raw['notificationId'];
            }
        }

        return new PushSendResult(
            success: true,
            status: PushStatus::Sent,
            providerMessageId: $providerMessageId,
            raw: $raw,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildRequestBody(PushSendInput $input): array
    {
        $body = ['applicationId' => $this->config->applicationId];
        $narrowed = $input instanceof WonderpushPushSendInput ? $input : null;

        $body['targetUserIds'] = $narrowed->targetUserIds ?? [$input->to];

        if ($narrowed !== null) {
            if ($narrowed->targetSegmentIds !== null) {
                $body['targetSegmentIds']  = $narrowed->targetSegmentIds;
            }
            if ($narrowed->customSegmentation !== null) {
                $body['customSegmentation'] = $narrowed->customSegmentation;
            }
        }

        // notification block.
        $alert = [];
        if (($input->title ?? '') !== '') {
            $alert['title'] = $input->title;
        }
        if (($input->body ?? '') !== '') {
            $alert['text']  = $input->body;
        }
        if ($narrowed !== null && $narrowed->subtitle !== null) {
            $alert['subtitle'] = $narrowed->subtitle;
        }

        $notification = [];
        if ($alert !== []) {
            $notification['alert'] = $alert;
        }
        if ($narrowed !== null) {
            if ($narrowed->sound !== null) {
                $notification['sound']  = $narrowed->sound;
            }
            if ($narrowed->badge !== null) {
                $notification['badge']  = $narrowed->badge;
            }
        }
        if ($input->data !== null) {
            $notification['custom'] = $input->data;
        }

        if ($narrowed !== null && $narrowed->notification !== null) {
            foreach ($narrowed->notification as $k => $v) {
                $notification[$k] = $v;
            }
            if (isset($narrowed->notification['alert']) && is_array($narrowed->notification['alert']) && $alert !== []) {
                $notification['alert'] = array_merge($alert, $narrowed->notification['alert']);
            }
        }

        if ($notification !== []) {
            $body['notification'] = $notification;
        }

        if ($narrowed !== null) {
            if ($narrowed->actions !== null) {
                $body['actions']    = $narrowed->actions;
            }
            if ($narrowed->categories !== null) {
                $body['categories'] = $narrowed->categories;
            }
        }

        return $body;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $providerCode = match (true) {
            $statusCode === 401                  => ProviderCode::AuthFailed,
            $statusCode === 404                  => ProviderCode::InvalidRecipient,
            $statusCode === 429                  => ProviderCode::RateLimited,
            $statusCode === 500, $statusCode === 503 => ProviderCode::ProviderUnavailable,
            $statusCode === 400                  => ProviderCode::InvalidRequest,
            $statusCode >= 400 && $statusCode < 500 => ProviderCode::InvalidRequest,
            $statusCode >= 500                   => ProviderCode::ProviderUnavailable,
            default                              => ProviderCode::Unknown,
        };

        $baseMessage = "WonderPush HTTP {$statusCode}";
        if (is_array($body) && isset($body['error']) && is_array($body['error']) && isset($body['error']['message']) && is_string($body['error']['message'])) {
            $baseMessage = $body['error']['message'];
        }

        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
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
