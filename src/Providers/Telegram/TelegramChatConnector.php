<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Telegram;

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
 * Telegram Bot API chat connector. Mirrors TS `TelegramChatConnector`
 * (notifications-ts/src/providers/telegram/telegram.connector.ts).
 *
 * Auth: token-auth — `botToken` embeds in the URL path
 * (`/bot<token>/sendMessage`).
 *
 * **retry-after outlier:** Telegram emits `parameters.retry_after` in the JSON
 * body, NOT a `Retry-After` HTTP header. The mapper inspects the body's
 * `parameters.retry_after` and surfaces it inside `cause`; the wrapper itself
 * never retries.
 */
final class TelegramChatConnector extends BaseConnector implements ChatConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly TelegramConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'telegram';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(ChatSendInput $input): ChatSendResult
    {
        if ($input->to === null || $input->to === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Telegram requires `to` (chat_id or @channelname).',
                statusCode: 400,
            );
        }

        $connectorBody = [
            'chat_id' => $input->to,
            'text'    => $input->body,
        ];

        if ($input instanceof TelegramChatSendInput) {
            if ($input->parseMode !== null) {
                $connectorBody['parse_mode']           = $input->parseMode;
            }
            if ($input->entities !== null) {
                $connectorBody['entities']             = $input->entities;
            }
            if ($input->disableNotification !== null) {
                $connectorBody['disable_notification'] = $input->disableNotification;
            }
            if ($input->protectContent !== null) {
                $connectorBody['protect_content']      = $input->protectContent;
            }
            if ($input->replyParameters !== null) {
                $connectorBody['reply_parameters']     = $input->replyParameters;
            }
            if ($input->replyMarkup !== null) {
                $connectorBody['reply_markup']         = $input->replyMarkup;
            }
            if ($input->linkPreviewOptions !== null) {
                $connectorBody['link_preview_options'] = $input->linkPreviewOptions;
            }
            if ($input->messageThreadId !== null) {
                $connectorBody['message_thread_id']    = $input->messageThreadId;
            }
        }

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => ['Content-Type' => 'application/json'], 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $url = "https://api.telegram.org/bot{$this->config->botToken}/sendMessage";

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);
        $status = $response->getStatusCode();

        /** @var array<string,mixed>|null $raw */
        $raw = $this->decodeJson($response);

        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $raw, $response);
        }

        if (!is_array($raw)) {
            // Empty or unparseable 2xx body — do not fabricate a 400.
            throw new ConnectorError(
                providerCode: ProviderCode::ProviderUnavailable,
                providerMessage: 'Unparseable success body',
                cause: ['raw' => null],
                statusCode: $status,
            );
        }

        if (isset($raw['ok']) && $raw['ok'] === false) {
            // Soft-error path: HTTP 200 with `ok: false`.
            $softCode = isset($raw['error_code']) && is_int($raw['error_code']) ? $raw['error_code'] : 400;
            throw $this->mapVendorError($softCode, $raw, $response);
        }

        $messageId = null;
        if (isset($raw['result']) && is_array($raw['result']) && isset($raw['result']['message_id'])) {
            $messageId = (string) $raw['result']['message_id'];
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
        $description = null;
        if (is_array($body) && isset($body['description']) && is_string($body['description'])) {
            $description = $body['description'];
        }

        $retryAfter = null;
        if (is_array($body) && isset($body['parameters']) && is_array($body['parameters']) && isset($body['parameters']['retry_after']) && is_int($body['parameters']['retry_after'])) {
            $retryAfter = $body['parameters']['retry_after'];
        }

        $providerCode = $this->classifyError($statusCode, $description);

        $base = $description ?? "Telegram HTTP {$statusCode}";
        $providerMessage = $retryAfter !== null
            ? "{$base} (Retry-After: {$retryAfter} seconds)"
            : $base;

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            // Telegram sources retry-after from the JSON body (an int), not a
            // header — it is both the raw value and the parsed seconds (a documented design decision).
            cause: ['raw' => $body, 'retryAfter' => $retryAfter, 'retryAfterSeconds' => $retryAfter],
            statusCode: $statusCode,
        );
    }

    private function classifyError(int $statusCode, ?string $description): ProviderCode
    {
        if ($statusCode === 401 || $statusCode === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode === 400) {
            $desc = $description ?? '';
            if (preg_match('/chat not found/i', $desc) === 1) {
                return ProviderCode::InvalidRecipient;
            }
            if (preg_match('/user_id_invalid|peer_id_invalid|chat_id_invalid/i', $desc) === 1) {
                return ProviderCode::InvalidRecipient;
            }
            return ProviderCode::InvalidRequest;
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
