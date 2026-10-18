<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Line;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Base\BaseConnector;
use Thinwrap\Notifications\Contract\ChatConnectorInterface;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatSendResult;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Util\Passthrough;

/**
 * LINE Messaging API (Push) chat connector. Mirrors TS `LineChatConnector`
 * (notifications-ts/src/providers/line/line.connector.ts).
 *
 * Auth: token-auth — `Authorization: Bearer <channelAccessToken>`.
 * Endpoint: `POST https://api.line.me/v2/bot/message/push`.
 *
 * **`body → messages[]` synthesis (casing-locality outlier wire translation):** LINE's
 * wire requires `messages: LineMessage[]` (1–5 messages per call); Thinwrap's
 * baseline `ChatSendInput::$body` (single string) is bridged by wrapping into
 * `[['type' => 'text', 'text' => $input->body]]`. When `$messages` is set on
 * `LineChatSendInput`, it is used directly and `$body` is IGNORED — parallel
 * to WhatsApp Business 3.35's template/interactive override.
 *
 * Reply API / Multicast / Broadcast / Narrowcast paths are out-of-scope for
 * v1.0; consumers can use `_passthrough` or future narrowed-input additions.
 */
final class LineChatConnector extends BaseConnector implements ChatConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://api.line.me/v2/bot/message/push';

    public function __construct(
        #[\SensitiveParameter]
        private readonly LineConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'line';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(ChatSendInput $input): ChatSendResult
    {
        if ($input->to === null || $input->to === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'LINE requires `to` (userId / groupId / roomId).',
                statusCode: 400,
            );
        }

        // body → messages[] synthesis. When narrowed messages set, body ignored
        // (parallel to WhatsApp template/interactive override semantics).
        $messages = $input instanceof LineChatSendInput && $input->messages !== null
            ? $input->messages
            : [['type' => 'text', 'text' => $input->body]];

        $connectorBody = ['to' => $input->to, 'messages' => $messages];
        if ($input instanceof LineChatSendInput) {
            if ($input->notificationDisabled !== null) {
                $connectorBody['notificationDisabled']   = $input->notificationDisabled;
            }
            if ($input->customAggregationUnits !== null) {
                $connectorBody['customAggregationUnits'] = $input->customAggregationUnits;
            }
        }

        $connectorHeaders = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->channelAccessToken,
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

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);
        $status = $response->getStatusCode();
        /** @var array<string,mixed>|null $raw */
        $raw = $this->decodeJson($response);

        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $raw, $response);
        }

        $providerMessageId = null;
        if (
            is_array($raw)
            && isset($raw['sentMessages'])
            && is_array($raw['sentMessages'])
            && isset($raw['sentMessages'][0])
            && is_array($raw['sentMessages'][0])
            && isset($raw['sentMessages'][0]['id'])
            && is_string($raw['sentMessages'][0]['id'])
        ) {
            $providerMessageId = $raw['sentMessages'][0]['id'];
        }

        return new ChatSendResult(
            success: true,
            status: ChatStatus::Sent,
            providerMessageId: $providerMessageId,
            raw: $raw ?? [],
        );
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $vendorMessage = is_array($body) && isset($body['message']) && is_string($body['message']) && $body['message'] !== ''
            ? $body['message']
            : "LINE HTTP {$statusCode}";

        $providerCode = $this->classifyError($statusCode, $body, $vendorMessage);

        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $providerMessage = $parsedSeconds !== null
            ? "{$vendorMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $vendorMessage;

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
     * @param array<string,mixed>|null $body
     */
    private function classifyError(int $statusCode, ?array $body, string $message): ProviderCode
    {
        if ($statusCode === 401 || $statusCode === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode >= 500) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($statusCode === 400) {
            if (preg_match('/not found|userid/i', $message) === 1) {
                return ProviderCode::InvalidRecipient;
            }
            if (is_array($body) && isset($body['details']) && is_array($body['details'])) {
                foreach ($body['details'] as $detail) {
                    if (
                        is_array($detail)
                        && isset($detail['message'])
                        && is_string($detail['message'])
                        && preg_match('/invalid user/i', $detail['message']) === 1
                    ) {
                        return ProviderCode::InvalidRecipient;
                    }
                }
            }

            return ProviderCode::InvalidRequest;
        }

        return ProviderCode::Unknown;
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
