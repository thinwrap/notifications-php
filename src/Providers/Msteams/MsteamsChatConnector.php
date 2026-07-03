<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Msteams;

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
 * MS Teams Incoming Webhook chat connector. Mirrors TS `MsTeamsChatConnector`.
 *
 * Webhook-URL-as-auth: no Authorization header. MS Teams returns plain
 * text `"1"` on success; `providerMessageId` is always `null`.
 *
 * **casing-locality outlier:** Teams expects a fixed envelope wrapping the Adaptive Card.
 */
final class MsteamsChatConnector extends BaseConnector implements ChatConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly MsteamsConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'ms-teams';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(ChatSendInput $input): ChatSendResult
    {
        if ($this->config->webhookUrl === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'MS Teams requires `webhookUrl` in config (webhook-URL-as-auth).',
                statusCode: 400,
            );
        }

        $providedCard = $input instanceof MsteamsChatSendInput ? $input->card : null;
        $card = $providedCard ?? [
            'type'    => 'AdaptiveCard',
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'version' => '1.4',
            'body'    => [['type' => 'TextBlock', 'text' => $input->body, 'wrap' => true]],
        ];

        $connectorBody = [
            'type'        => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content'     => $card,
            ]],
        ];

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => ['Content-Type' => 'application/json'], 'query' => []],
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
            $rawText = (string) $response->getBody();
            throw $this->mapVendorError($status, $rawText !== '' ? ['_text' => $rawText] : null, $response);
        }

        $rawText = (string) $response->getBody();

        return new ChatSendResult(
            success: true,
            status: ChatStatus::Sent,
            providerMessageId: null,
            raw: $rawText,
        );
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;

        $bodyText = '';
        if (is_array($body) && isset($body['_text']) && is_string($body['_text'])) {
            $bodyText = $body['_text'];
        }

        $isInvalidWebhook = $statusCode === 400 && preg_match('/invalid webhook/i', $bodyText) === 1;

        $providerCode = match (true) {
            $isInvalidWebhook                                              => ProviderCode::AuthFailed,
            $statusCode === 401, $statusCode === 403, $statusCode === 404 => ProviderCode::AuthFailed,
            $statusCode === 429                                            => ProviderCode::RateLimited,
            $statusCode === 400                                            => ProviderCode::InvalidRequest,
            $statusCode >= 500                                             => ProviderCode::ProviderUnavailable,
            default                                                        => ProviderCode::Unknown,
        };

        $baseMessage = $bodyText !== '' ? $bodyText : "MS Teams HTTP {$statusCode}";
        $providerMessage = $parsedSeconds !== null
            ? "{$baseMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $baseMessage;

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            cause: [
                'raw'       => $bodyText !== '' ? $bodyText : null,
                'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
                'retryAfterSeconds' => $parsedSeconds,
            ],
            statusCode: $statusCode,
        );
    }
}
