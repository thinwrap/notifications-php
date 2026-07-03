<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Slack;

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
 * Slack Incoming Webhook chat connector. Mirrors TS `SlackChatConnector`
 * (notifications-ts/src/providers/slack/slack.connector.ts).
 *
 * Webhook-URL-as-auth: no Authorization header; the webhookUrl IS the
 * credential. Slack Incoming Webhooks return plain text `"ok"` on success — no
 * JSON, no message_id; `providerMessageId` is always `null`.
 */
final class SlackChatConnector extends BaseConnector implements ChatConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly SlackConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'slack';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(ChatSendInput $input): ChatSendResult
    {
        if ($this->config->webhookUrl === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Slack requires `webhookUrl` in config (webhook-URL-as-auth).',
                statusCode: 400,
            );
        }

        $connectorBody = ['text' => $input->body];
        if ($input instanceof SlackChatSendInput) {
            if ($input->blocks !== null) {
                $connectorBody['blocks']        = $input->blocks;
            }
            if ($input->attachments !== null) {
                $connectorBody['attachments']   = $input->attachments;
            }
            if ($input->username !== null) {
                $connectorBody['username']      = $input->username;
            }
            if ($input->iconEmoji !== null) {
                $connectorBody['icon_emoji']    = $input->iconEmoji;
            }
            if ($input->iconUrl !== null) {
                $connectorBody['icon_url']      = $input->iconUrl;
            }
            if ($input->threadTs !== null) {
                $connectorBody['thread_ts']     = $input->threadTs;
            }
            if ($input->mrkdwn !== null) {
                $connectorBody['mrkdwn']        = $input->mrkdwn;
            }
            if ($input->unfurlLinks !== null) {
                $connectorBody['unfurl_links']  = $input->unfurlLinks;
            }
            if ($input->unfurlMedia !== null) {
                $connectorBody['unfurl_media']  = $input->unfurlMedia;
            }
            if ($input->linkNames !== null) {
                $connectorBody['link_names']    = $input->linkNames;
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

        $providerCode = match (true) {
            $statusCode === 401, $statusCode === 403, $statusCode === 404 => ProviderCode::AuthFailed,
            $statusCode === 429                                            => ProviderCode::RateLimited,
            $statusCode === 400                                            => ProviderCode::InvalidRequest,
            $statusCode >= 500                                             => ProviderCode::ProviderUnavailable,
            default                                                        => ProviderCode::Unknown,
        };

        $bodyText = '';
        if (is_array($body) && isset($body['_text']) && is_string($body['_text'])) {
            $bodyText = $body['_text'];
        }
        $baseMessage = $bodyText !== '' ? $bodyText : "Slack HTTP {$statusCode}";
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
