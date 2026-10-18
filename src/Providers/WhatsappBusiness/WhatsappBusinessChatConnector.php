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
        #[\SensitiveParameter]
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

        // Config values placed in the path are escaped so a stray `/`, `?` or
        // `#` cannot re-point the credentialed request at another resource.
        $url = 'https://graph.facebook.com/' . rawurlencode($this->config->graphApiVersion) . '/' . rawurlencode($this->config->phoneNumberId) . '/messages';
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

    /**
     * WhatsApp Cloud API `error.code`, per Meta's error-code reference; the
     * HTTP status applies only when the code is not listed.
     *
     * @var array<int,ProviderCode>
     */
    private const ERROR_CODES = [
        0 => ProviderCode::AuthFailed,              // "We were unable to authenticate the app user"
        3 => ProviderCode::AuthFailed,              // capability / permission
        10 => ProviderCode::AuthFailed,             // "Permission is either not granted or has been removed"
        190 => ProviderCode::AuthFailed,            // "Your access token has expired"
        368 => ProviderCode::AuthFailed,            // temporarily blocked for policy violations
        131005 => ProviderCode::AuthFailed,         // access denied
        131031 => ProviderCode::AuthFailed,         // business account locked / restricted
        4 => ProviderCode::RateLimited,             // app-level rate limit
        80007 => ProviderCode::RateLimited,         // "WhatsApp Business Account has reached its rate limit"
        130429 => ProviderCode::RateLimited,        // "Rate limit hit" (Cloud API throughput)
        131048 => ProviderCode::RateLimited,        // spam rate limit hit
        131056 => ProviderCode::RateLimited,        // "Too many messages sent from the sender phone number to the same recipient"
        131021 => ProviderCode::InvalidRecipient,   // sender and recipient are the same number
        131026 => ProviderCode::InvalidRecipient,   // message undeliverable to this recipient
        100 => ProviderCode::InvalidRequest,        // invalid parameter
        131008 => ProviderCode::InvalidRequest,     // "The request is missing a required parameter."
        131009 => ProviderCode::InvalidRequest,     // "One or more parameter values are invalid."
        131042 => ProviderCode::InvalidRequest,     // business eligibility / payment issue
        131045 => ProviderCode::InvalidRequest,     // phone-number registration error
        131047 => ProviderCode::InvalidRequest,     // "More than 24 hours have passed since the recipient last replied" — send a template
        131051 => ProviderCode::InvalidRequest,     // unsupported message type
        132000 => ProviderCode::InvalidRequest,     // template parameter count mismatch
        132001 => ProviderCode::InvalidRequest,     // template does not exist
        132012 => ProviderCode::InvalidRequest,     // template parameter format mismatch
        133010 => ProviderCode::InvalidRequest,     // "Phone number not registered on the WhatsApp Business Platform." (the sender)
        1 => ProviderCode::ProviderUnavailable,     // unknown API error
        2 => ProviderCode::ProviderUnavailable,     // API service
        131000 => ProviderCode::ProviderUnavailable, // "Something went wrong"
        131016 => ProviderCode::ProviderUnavailable, // "A service is temporarily unavailable."
        131057 => ProviderCode::ProviderUnavailable, // business account in maintenance mode
    ];

    private function classifyError(int $statusCode, ?int $errorCode): ProviderCode
    {
        if ($errorCode !== null) {
            if (isset(self::ERROR_CODES[$errorCode])) {
                return self::ERROR_CODES[$errorCode];
            }
            // 200-299: "Permission is either not granted or has been removed".
            if ($errorCode >= 200 && $errorCode <= 299) {
                return ProviderCode::AuthFailed;
            }
        }

        return match (true) {
            $statusCode === 401 || $statusCode === 403 => ProviderCode::AuthFailed,
            $statusCode === 429 => ProviderCode::RateLimited,
            $statusCode >= 500 => ProviderCode::ProviderUnavailable,
            $statusCode === 400 => ProviderCode::InvalidRequest,
            default => ProviderCode::Unknown,
        };
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
