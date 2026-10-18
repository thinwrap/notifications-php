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
 * Webhook-URL-as-auth: no Authorization header. A Workflows webhook answers
 * 202 with an empty body; a legacy Office 365 Connector answers 200 with the
 * plain text `"1"`. `providerMessageId` is always `null`.
 *
 * **casing-locality outlier:** Teams expects a fixed envelope wrapping the Adaptive Card.
 */
final class MsteamsChatConnector extends BaseConnector implements ChatConnectorInterface
{
    public readonly string $id;

    public function __construct(
        #[\SensitiveParameter]
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

        if (!str_starts_with(strtolower($this->config->webhookUrl), 'https://')) {
            // The webhook URL IS the credential; refuse cleartext http.
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'MS Teams webhookUrl must be an https:// URL.',
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

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            $rawText = (string) $response->getBody();
            throw $this->mapVendorError($status, $rawText !== '' ? ['_text' => $rawText] : null, $response);
        }

        $rawText = (string) $response->getBody();

        // A Workflows webhook ("When a Teams webhook request is received") is a
        // Logic Apps Request trigger, which answers 202 ACCEPTED with no body as
        // soon as the run is queued — that IS the success signal.
        if ($status !== 202) {
            // Legacy Office 365 Connectors report failures (including throttling)
            // as HTTP 200 with an error string in the body, e.g. "...Teams
            // endpoint returned HTTP error 429 with ContextId...".
            if (preg_match('/HTTP error (\d{3})/i', $rawText, $m) === 1) {
                throw $this->mapVendorError((int) $m[1], ['_text' => $rawText], $response);
            }
            // A legacy connector host answers the literal "1" on success, so any
            // other body from one is a failure; Workflows hosts carry no such
            // contract and their 2xx stands on its own.
            if (self::isLegacyConnectorHost($url) && trim($rawText) !== '1') {
                throw $this->mapVendorError(502, ['_text' => $rawText], $response);
            }
        }

        return new ChatSendResult(
            success: true,
            status: ChatStatus::Sent,
            providerMessageId: null,
            raw: $rawText,
        );
    }

    /**
     * Office 365 Connector webhooks live on `{tenant}.webhook.office.com` (and
     * the older `outlook.office.com`); Workflows URLs are on Logic Apps /
     * Power Platform hosts.
     */
    private static function isLegacyConnectorHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }
        $host = strtolower($host);

        return $host === 'outlook.office.com' || str_ends_with($host, '.webhook.office.com');
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
