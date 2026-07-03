<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Discord;

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
 * Discord webhook chat connector. Mirrors TS `DiscordChatConnector`.
 *
 * Webhook-URL-as-auth. `?wait=true` is always appended so Discord
 * returns the created message ID in the response body. `?thread_id=<id>` is
 * appended when `threadId` is set.
 *
 * **Dual retry-after path:** Discord emits body `retry_after`
 * (seconds, float since API v8) AND header `Retry-After` (legacy, historically
 * milliseconds). Body wins; header is heuristic-parsed (values > 1000 → ms).
 */
final class DiscordChatConnector extends BaseConnector implements ChatConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly DiscordConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'discord';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(ChatSendInput $input): ChatSendResult
    {
        if ($this->config->webhookUrl === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Discord requires `webhookUrl` in config (webhook-URL-as-auth).',
                statusCode: 400,
            );
        }

        $connectorBody = ['content' => $input->body];
        $threadId = null;
        if ($input instanceof DiscordChatSendInput) {
            if ($input->embeds !== null) {
                $connectorBody['embeds']           = $input->embeds;
            }
            if ($input->components !== null) {
                $connectorBody['components']       = $input->components;
            }
            if ($input->username !== null) {
                $connectorBody['username']         = $input->username;
            }
            if ($input->avatarUrl !== null) {
                $connectorBody['avatar_url']       = $input->avatarUrl;
            }
            if ($input->tts !== null) {
                $connectorBody['tts']              = $input->tts;
            }
            if ($input->flags !== null) {
                $connectorBody['flags']            = $input->flags;
            }
            if ($input->allowedMentions !== null) {
                $connectorBody['allowed_mentions'] = $input->allowedMentions;
            }
            if ($input->threadName !== null) {
                $connectorBody['thread_name']      = $input->threadName;
            }
            $threadId = $input->threadId;
        }

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => ['Content-Type' => 'application/json'], 'query' => []],
            $input->_passthrough,
        );

        $queryParams = array_merge(['wait' => 'true'], $merged['query']);
        if ($threadId !== null) {
            $queryParams['thread_id'] = $threadId;
        }

        $url = $this->config->webhookUrl;
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($queryParams);

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            /** @var array<string,mixed>|null $errBody */
            $errBody = $this->decodeJson($response);
            throw $this->mapVendorError($status, $errBody, $response);
        }

        /** @var array<string,mixed>|null $raw */
        $raw = $status === 204 ? null : $this->decodeJson($response);
        $messageId = null;
        if (is_array($raw) && isset($raw['id']) && is_string($raw['id'])) {
            $messageId = $raw['id'];
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
        $retryAfterHeader = $response->getHeaderLine('Retry-After');
        $retryAfterBody = null;
        if (is_array($body) && isset($body['retry_after']) && (is_int($body['retry_after']) || is_float($body['retry_after']))) {
            $retryAfterBody = (float) $body['retry_after'];
        }

        $retryAfterSeconds = null;
        if ($retryAfterBody !== null) {
            $retryAfterSeconds = (int) ceil($retryAfterBody);
        } elseif ($retryAfterHeader !== '' && is_numeric($retryAfterHeader)) {
            $parsed = (float) $retryAfterHeader;
            $retryAfterSeconds = $parsed > 1000.0
                ? (int) ceil($parsed / 1000.0)
                : (int) ceil($parsed);
        }

        $providerCode = match (true) {
            $statusCode === 401, $statusCode === 403, $statusCode === 404 => ProviderCode::AuthFailed,
            $statusCode === 429                                            => ProviderCode::RateLimited,
            $statusCode === 400                                            => ProviderCode::InvalidRequest,
            $statusCode >= 500                                             => ProviderCode::ProviderUnavailable,
            default                                                        => ProviderCode::Unknown,
        };

        $baseMessage = "Discord HTTP {$statusCode}";
        if (is_array($body) && isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            $baseMessage = $body['message'];
        }
        $providerMessage = $retryAfterSeconds !== null
            ? "{$baseMessage} (Retry-After: {$retryAfterSeconds} seconds)"
            : $baseMessage;

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            cause: [
                'raw'               => $body,
                'retryAfter'        => $retryAfterHeader !== '' ? $retryAfterHeader : null,
                'retryAfterSeconds' => $retryAfterSeconds,
                'retryAfterBody'    => $retryAfterBody,
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
