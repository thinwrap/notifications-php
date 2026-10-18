<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\GoogleChat;

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
 * Google Chat incoming-webhook connector. Mirrors TS `GoogleChatChatConnector`
 * (notifications-ts/src/providers/google-chat/google-chat.connector.ts).
 *
 * Webhook-URL-as-auth: no Authorization header; the webhookUrl IS the
 * credential (with embedded `?key=<key>&token=<token>` query params).
 *
 * Unusual in the Chat wave for using **camelCase wire keys** (Google API style
 * guide). Per casing-locality, all wire keys are written explicitly in the array
 * literal — no shared casing transform.
 *
 * Unlike Slack/MS Teams (plain-text response), Google Chat returns a full JSON
 * `Message` resource on success; `providerMessageId` carries the full resource
 * name `spaces/<space-id>/messages/<message-id>`.
 */
final class GoogleChatChatConnector extends BaseConnector implements ChatConnectorInterface
{
    public readonly string $id;

    public function __construct(
        #[\SensitiveParameter]
        private readonly GoogleChatConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'google-chat';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(ChatSendInput $input): ChatSendResult
    {
        if ($this->config->webhookUrl === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Google Chat requires `webhookUrl` in config (webhook-URL-as-auth).',
                statusCode: 400,
            );
        }

        if (!str_starts_with(strtolower($this->config->webhookUrl), 'https://')) {
            // The webhook URL IS the credential; refuse cleartext http.
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Google Chat webhookUrl must be an https:// URL.',
                statusCode: 400,
            );
        }

        $connectorBody = ['text' => $input->body];
        if ($input instanceof GoogleChatChatSendInput) {
            if ($input->cardsV2 !== null) {
                $connectorBody['cardsV2']      = $input->cardsV2;
            }
            if ($input->thread !== null) {
                $connectorBody['thread']       = $input->thread;
            }
            if ($input->fallbackText !== null) {
                $connectorBody['fallbackText'] = $input->fallbackText;
            }
        }

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => ['Content-Type' => 'application/json; charset=UTF-8'], 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $url = $this->config->webhookUrl;
        if ($merged['query'] !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($merged['query']);
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
        $messageId = null;
        if (is_array($raw) && isset($raw['name']) && is_string($raw['name'])) {
            $messageId = $raw['name'];
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
        $errorObj = (is_array($body) && isset($body['error']) && is_array($body['error'])) ? $body['error'] : null;
        $vendorMessage = (is_array($errorObj) && isset($errorObj['message']) && is_string($errorObj['message']) && $errorObj['message'] !== '')
            ? $errorObj['message']
            : "Google Chat HTTP {$statusCode}";

        $providerCode = match (true) {
            $statusCode === 401, $statusCode === 403, $statusCode === 404 => ProviderCode::AuthFailed,
            $statusCode === 429                                            => ProviderCode::RateLimited,
            $statusCode === 400                                            => $this->classify400($vendorMessage),
            $statusCode >= 500                                             => ProviderCode::ProviderUnavailable,
            default                                                        => ProviderCode::Unknown,
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

    private function classify400(string $message): ProviderCode
    {
        $low = strtolower($message);
        if (str_contains($low, 'space') && (str_contains($low, 'invalid') || str_contains($low, 'not found'))) {
            return ProviderCode::InvalidRecipient;
        }

        return ProviderCode::InvalidRequest;
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
