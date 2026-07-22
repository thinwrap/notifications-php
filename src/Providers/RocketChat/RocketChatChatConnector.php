<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\RocketChat;

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
 * Rocket.Chat REST API chat connector — PHP-side intentionally diverges from
 * the TS connector (webhook auth). PHP uses two-header REST auth:
 *   `X-Auth-Token` + `X-User-Id` against `<baseUrl>/api/v1/chat.postMessage`.
 *
 * Tokens are server-issued via `/api/v1/login`; the wrapper does NOT perform
 * login per the stateless-wrapper rule — consumers handle token
 * acquisition + caching.
 *
 * REST path returns `message._id` on success (webhooks don't) — surfaced via
 * `providerMessageId`. Soft-reject branch by design: HTTP 200 with
 * `{success: false}` returns `ChatSendResult(success=false, status=Rejected)`
 * — NOT thrown. 4xx/5xx hard-error throws via `mapVendorError`.
 */
final class RocketChatChatConnector extends BaseConnector implements ChatConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly RocketChatConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'rocket-chat';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    /**
     * @return array<string,string>
     */
    protected function getAuthHeaders(): array
    {
        return [
            'X-Auth-Token' => $this->config->authToken,
            'X-User-Id'    => $this->config->userId,
        ];
    }

    public function send(ChatSendInput $input): ChatSendResult
    {
        if ($input->to === null || $input->to === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Rocket.Chat requires `to` (#channel, @user, or roomId).',
                statusCode: 400,
            );
        }

        // The X-Auth-Token / X-User-Id pair is sent as request headers — reject a
        // non-https baseUrl so the credentials are never transmitted in cleartext.
        if (strtolower((string) parse_url($this->config->baseUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Rocket.Chat `baseUrl` must use https (auth token is sent as request headers).',
                statusCode: 400,
            );
        }

        $connectorBody = [
            'channel' => $input->to,
            'text'    => $input->body,
        ];

        if ($input instanceof RocketChatChatSendInput) {
            if ($input->alias !== null) {
                $connectorBody['alias']       = $input->alias;
            }
            if ($input->emoji !== null) {
                $connectorBody['emoji']       = $input->emoji;
            }
            if ($input->avatar !== null) {
                $connectorBody['avatar']      = $input->avatar;
            }
            if ($input->attachments !== null) {
                $connectorBody['attachments'] = $input->attachments;
            }
        }

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => [], 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $url = "{$this->config->baseUrl}/api/v1/chat.postMessage";

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);
        $status = $response->getStatusCode();
        /** @var array<string,mixed>|null $raw */
        $raw = $this->decodeJson($response);

        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $raw, $response);
        }

        // Soft-reject branch by design: HTTP 200 with success=false → Rejected,
        // not thrown. HTTP status is the discriminator.
        if (is_array($raw) && isset($raw['success']) && $raw['success'] === false) {
            return new ChatSendResult(
                success: false,
                status: ChatStatus::Rejected,
                providerMessageId: null,
                raw: $raw,
            );
        }

        $messageId = null;
        if (is_array($raw) && isset($raw['message']) && is_array($raw['message']) && isset($raw['message']['_id']) && is_string($raw['message']['_id'])) {
            $messageId = $raw['message']['_id'];
        }

        return new ChatSendResult(
            success: true,
            status: ChatStatus::Sent,
            providerMessageId: $messageId,
            raw: $raw,
        );
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $vendorMessage = "Rocket.Chat HTTP {$statusCode}";
        if (is_array($body)) {
            if (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
                $vendorMessage = $body['error'];
            } elseif (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                $vendorMessage = $body['message'];
            }
        }

        $errorType = (is_array($body) && isset($body['errorType']) && is_string($body['errorType'])) ? $body['errorType'] : null;

        $providerCode = match (true) {
            $statusCode === 401, $statusCode === 403          => ProviderCode::AuthFailed,
            $statusCode === 400 && $errorType === 'invalid-channel' => ProviderCode::InvalidRecipient,
            $statusCode === 400                                => ProviderCode::InvalidRequest,
            $statusCode === 429                                => ProviderCode::RateLimited,
            $statusCode >= 500                                 => ProviderCode::ProviderUnavailable,
            default                                            => ProviderCode::Unknown,
        };

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
