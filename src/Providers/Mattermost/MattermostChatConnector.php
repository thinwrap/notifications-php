<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mattermost;

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
 * Mattermost Incoming Webhook chat connector. Mirrors TS
 * `MattermostChatConnector` (notifications-ts/src/providers/mattermost/mattermost.connector.ts).
 *
 * Webhook-URL-as-auth: no Authorization header; the webhookUrl IS the
 * credential.
 *
 * Mattermost Incoming Webhooks return plain text `"ok"` on success — no JSON,
 * no message_id; `providerMessageId` is always `null`. Mattermost adopted
 * Slack's legacy attachment format verbatim; the camelCase→snake_case mapping
 * for the 7 attachment fields lives in the private `toWireAttachment` method
 * per casing-locality (explicit per-connector mapping, NOT generic middleware).
 */
final class MattermostChatConnector extends BaseConnector implements ChatConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly MattermostConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'mattermost';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(ChatSendInput $input): ChatSendResult
    {
        if ($this->config->webhookUrl === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Mattermost requires `webhookUrl` in config (webhook-URL-as-auth).',
                statusCode: 400,
            );
        }

        if (!str_starts_with(strtolower($this->config->webhookUrl), 'https://')) {
            // The webhook URL IS the credential; refuse cleartext http.
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Mattermost webhookUrl must be an https:// URL.',
                statusCode: 400,
            );
        }

        $connectorBody = ['text' => $input->body];
        if ($input instanceof MattermostChatSendInput) {
            if ($input->attachments !== null) {
                $connectorBody['attachments'] = array_map(
                    fn(SlackCompatAttachment $a): array => $this->toWireAttachment($a),
                    $input->attachments,
                );
            }
            if ($input->username !== null) {
                $connectorBody['username']   = $input->username;
            }
            if ($input->iconUrl !== null) {
                $connectorBody['icon_url']   = $input->iconUrl;
            }
            if ($input->iconEmoji !== null) {
                $connectorBody['icon_emoji'] = $input->iconEmoji;
            }
            if ($input->channel !== null) {
                $connectorBody['channel']    = $input->channel;
            }
            if ($input->props !== null) {
                $connectorBody['props']      = $input->props;
            }
            if ($input->type !== null) {
                $connectorBody['type']       = $input->type;
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
     * Map camelCase narrowed attachment fields to Slack-compat snake_case wire
     * keys. Lives on the connector (NOT a shared utility) per casing-locality.
     *
     * @return array<string,mixed>
     */
    private function toWireAttachment(SlackCompatAttachment $a): array
    {
        $w = [];
        if ($a->color !== null) {
            $w['color']       = $a->color;
        }
        if ($a->pretext !== null) {
            $w['pretext']     = $a->pretext;
        }
        if ($a->authorName !== null) {
            $w['author_name'] = $a->authorName;
        }
        if ($a->authorLink !== null) {
            $w['author_link'] = $a->authorLink;
        }
        if ($a->authorIcon !== null) {
            $w['author_icon'] = $a->authorIcon;
        }
        if ($a->title !== null) {
            $w['title']       = $a->title;
        }
        if ($a->titleLink !== null) {
            $w['title_link']  = $a->titleLink;
        }
        if ($a->text !== null) {
            $w['text']        = $a->text;
        }
        if ($a->fields !== null) {
            $w['fields']      = $a->fields;
        }
        if ($a->imageUrl !== null) {
            $w['image_url']   = $a->imageUrl;
        }
        if ($a->thumbUrl !== null) {
            $w['thumb_url']   = $a->thumbUrl;
        }
        if ($a->footer !== null) {
            $w['footer']      = $a->footer;
        }
        if ($a->footerIcon !== null) {
            $w['footer_icon'] = $a->footerIcon;
        }
        if ($a->timestamp !== null) {
            $w['timestamp']   = $a->timestamp;
        }
        if ($a->fallback !== null) {
            $w['fallback']    = $a->fallback;
        }

        return $w;
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

        $providerCode = match (true) {
            $statusCode === 401, $statusCode === 403, $statusCode === 404 => ProviderCode::AuthFailed,
            $statusCode === 429                                            => ProviderCode::RateLimited,
            $statusCode === 400                                            => ProviderCode::InvalidRequest,
            $statusCode >= 500                                             => ProviderCode::ProviderUnavailable,
            default                                                        => ProviderCode::Unknown,
        };

        $baseMessage = $bodyText !== '' ? $bodyText : "Mattermost HTTP {$statusCode}";
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
