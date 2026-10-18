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
        #[\SensitiveParameter]
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

        // notification block. Per the WonderPush notification object, the
        // platform-neutral alert carries only `title`/`text`; sound, badge and
        // subtitle live under the per-platform `alert.ios` / `alert.android`
        // sub-objects, and custom data goes under `push.custom`.
        $alert = [];
        if (($input->title ?? '') !== '') {
            $alert['title'] = $input->title;
        }
        if (($input->body ?? '') !== '') {
            $alert['text']  = $input->body;
        }
        if ($narrowed !== null) {
            if ($narrowed->subtitle !== null) {
                $alert['ios']['subtitle']    = $narrowed->subtitle;
            }
            if ($narrowed->sound !== null) {
                $alert['ios']['sound']       = $narrowed->sound;
                $alert['android']['sound']   = $narrowed->sound;
            }
            if ($narrowed->badge !== null) {
                $alert['ios']['badge']       = $narrowed->badge;
            }
        }

        $notification = [];
        if ($alert !== []) {
            $notification['alert'] = $alert;
        }
        if ($input->data !== null) {
            $notification['push'] = ['custom' => $input->data];
        }

        if ($narrowed !== null && $narrowed->notification !== null) {
            // Deep merge (caller wins per leaf) so a caller's own `alert.ios` /
            // `push` keys sit alongside the synthesized ones instead of
            // replacing the whole sub-object.
            $notification = Passthrough::merge(
                ['body' => $notification],
                ['body' => $narrowed->notification],
            )['body'];
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
