<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\WhatsappBusiness;

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
 * WhatsApp Business Cloud API chat connector. Mirrors TS
 * `WhatsAppChatConnector` (notifications-ts/src/providers/whatsapp-business/whatsapp-business.connector.ts).
 *
 * Token-auth: Bearer header carries a Meta Business Manager
 * system-user access token. URL embeds a versioned Graph API segment
 * (`/v21.0/...`) pinned via `config.graphApiVersion`.
 */
final class WhatsappBusinessChatConnector extends BaseConnector implements ChatConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly WhatsappBusinessConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'whatsapp-business';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(ChatSendInput $input): ChatSendResult
    {
        if ($input->to === null || $input->to === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'WhatsApp Business requires `to` (E.164 phone number).',
                statusCode: 400,
            );
        }

        $narrowed = $input instanceof WhatsappBusinessChatSendInput ? $input : null;
        $type = $narrowed !== null ? $narrowed->type : 'text';

        $connectorBody = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $input->to,
            'type'              => $type,
        ];

        if ($type === 'text') {
            $textShape = ['body' => $input->body];
            if ($narrowed !== null && $narrowed->previewUrl !== null) {
                $textShape['preview_url'] = $narrowed->previewUrl;
            }
            $connectorBody['text'] = $textShape;
        } elseif ($type === 'template') {
            if ($narrowed?->template === null) {
                throw new ConnectorError(
                    providerCode: ProviderCode::InvalidRequest,
                    providerMessage: "Missing payload for message type '{$type}'",
                    statusCode: 400,
                );
            }
            $connectorBody['template'] = $narrowed->template;
        } elseif ($type === 'interactive') {
            if ($narrowed?->interactive === null) {
                throw new ConnectorError(
                    providerCode: ProviderCode::InvalidRequest,
                    providerMessage: "Missing payload for message type '{$type}'",
                    statusCode: 400,
                );
            }
            $connectorBody['interactive'] = $narrowed->interactive;
        } else {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Missing payload for message type '{$type}'",
                statusCode: 400,
            );
        }

        if ($narrowed !== null && $narrowed->context !== null) {
            $connectorBody['context'] = ['message_id' => $narrowed->context['messageId']];
        }

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

        $url = "https://graph.facebook.com/{$this->config->graphApiVersion}/{$this->config->phoneNumberId}/messages";
        if ($merged['query'] !== []) {
            $url .= '?' . http_build_query($merged['query']);
        }

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);
        $status = $response->getStatusCode();

        /** @var array<string,mixed>|null $raw */
        $raw = $this->decodeJson($response);

        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $raw, $response);
        }

        $messageId = null;
        if (is_array($raw) && isset($raw['messages']) && is_array($raw['messages']) && isset($raw['messages'][0]) && is_array($raw['messages'][0]) && isset($raw['messages'][0]['id']) && is_string($raw['messages'][0]['id'])) {
            $messageId = $raw['messages'][0]['id'];
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
        $errorCode = (is_array($errorObj) && isset($errorObj['code']) && is_int($errorObj['code'])) ? $errorObj['code'] : null;
        $message = (is_array($errorObj) && isset($errorObj['message']) && is_string($errorObj['message'])) ? $errorObj['message'] : null;

        $providerCode = $this->classifyError($statusCode, $errorCode);

        $baseMessage = $message ?? "WhatsApp HTTP {$statusCode}";

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

    private function classifyError(int $statusCode, ?int $errorCode): ProviderCode
    {
        if ($errorCode === 190) {
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 401) {
            return ProviderCode::AuthFailed;
        }
        if ($errorCode === 131008 || $errorCode === 131026 || $errorCode === 131047) {
            return ProviderCode::InvalidRecipient;
        }
        if ($errorCode === 80007 || $errorCode === 80004 || $errorCode === 4) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($errorCode === 131051 || $errorCode === 131056) {
            return ProviderCode::InvalidRequest;
        }
        if ($statusCode === 400) {
            return ProviderCode::InvalidRequest;
        }
        if ($errorCode === 1 || $errorCode === 2) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($statusCode >= 500) {
            return ProviderCode::ProviderUnavailable;
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
