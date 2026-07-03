<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\OneSignal;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Base\BaseConnector;
use Thinwrap\Notifications\Contract\PushConnectorInterface;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushSendResult;
use Thinwrap\Notifications\DTO\Push\PushStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Util\Passthrough;

/**
 * OneSignal REST API v1 push connector. Mirrors TS `OneSignalPushConnector`
 * (notifications-ts/src/providers/one-signal/one-signal.connector.ts).
 *
 * Auth: `Authorization: Basic <restApiKey>` — REST API key sent verbatim
 * (no base64 wrapping), per OneSignal's documented `Authorization` scheme.
 *
 * OneSignal returns HTTP 200 even when all recipients are invalid; the
 * `errors` field carries the failure detail and is body-mapped via
 * `mapTicketError()` to `invalid_recipient` when known markers are present.
 */
final class OneSignalPushConnector extends BaseConnector implements PushConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://onesignal.com/api/v1/notifications';

    private const INVALID_RECIPIENT_MARKERS = [
        'all included players are not subscribed',
        'invalid_player_ids',
        'invalid_external_user_ids',
        'no subscriptions',
    ];

    public function __construct(
        private readonly OneSignalConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'one-signal';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(PushSendInput $input): PushSendResult
    {
        $connectorBody = $this->buildBody($input);

        $connectorHeaders = [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Basic ' . $this->config->restApiKey,
        ];

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => $connectorHeaders, 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $url = self::ENDPOINT;
        if ($merged['query'] !== []) {
            $url .= '?' . http_build_query($merged['query']);
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

        if (is_array($raw) && array_key_exists('errors', $raw)) {
            $ticketErr = $this->mapTicketError($raw, $status);
            if ($ticketErr !== null) {
                throw $ticketErr;
            }
        }

        if (!is_array($raw) || !isset($raw['id']) || !is_string($raw['id']) || $raw['id'] === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'OneSignal returned no notification id',
                cause: ['raw' => $raw, 'retryAfter' => null],
                statusCode: $status,
            );
        }

        return new PushSendResult(
            success: true,
            status: PushStatus::Sent,
            providerMessageId: $raw['id'],
            raw: $raw,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildBody(PushSendInput $input): array
    {
        $body = ['app_id' => $this->config->appId];

        $narrowed = $input instanceof OneSignalPushSendInput ? $input : null;

        $hasAugmentationRecipient = $narrowed !== null && (
            $narrowed->include_external_user_ids !== null
            || $narrowed->include_player_ids !== null
            || $narrowed->included_segments !== null
            || $narrowed->excluded_segments !== null
        );

        if ($hasAugmentationRecipient) {
            if ($narrowed->include_external_user_ids !== null) {
                $body['include_external_user_ids'] = $narrowed->include_external_user_ids;
            }
            if ($narrowed->include_player_ids !== null) {
                $body['include_player_ids']        = $narrowed->include_player_ids;
            }
            if ($narrowed->included_segments !== null) {
                $body['included_segments']         = $narrowed->included_segments;
            }
            if ($narrowed->excluded_segments !== null) {
                $body['excluded_segments']         = $narrowed->excluded_segments;
            }
        } else {
            // Empty-recipient contract: mirror TS `to !== undefined`
            // semantics. PHP `to` is a required string, so it is always
            // "provided"; an empty string is forwarded verbatim as `['']` rather
            // than omitting the recipient array.
            $body['include_subscription_ids'] = [$input->to];
        }

        // Title / body — localized maps win over base singletons.
        if ($narrowed !== null && $narrowed->headings !== null) {
            $body['headings'] = $narrowed->headings;
        } elseif (($input->title ?? '') !== '') {
            $body['headings'] = ['en' => $input->title];
        }

        if ($narrowed !== null && $narrowed->contents !== null) {
            $body['contents'] = $narrowed->contents;
        } elseif (($input->body ?? '') !== '') {
            $body['contents'] = ['en' => $input->body];
        }

        if ($input->data !== null) {
            $body['data'] = $input->data;
        }

        if ($narrowed !== null) {
            if ($narrowed->ttl !== null) {
                $body['ttl']                = $narrowed->ttl;
            }
            if ($narrowed->priority !== null) {
                $body['priority']           = $narrowed->priority;
            }
            if ($narrowed->ios_attachments !== null) {
                $body['ios_attachments']    = $narrowed->ios_attachments;
            }
            if ($narrowed->big_picture !== null) {
                $body['big_picture']        = $narrowed->big_picture;
            }
            if ($narrowed->android_channel_id !== null) {
                $body['android_channel_id'] = $narrowed->android_channel_id;
            }
            if ($narrowed->ios_sound !== null) {
                $body['ios_sound']          = $narrowed->ios_sound;
            }
            if ($narrowed->android_sound !== null) {
                $body['android_sound']      = $narrowed->android_sound;
            }
            if ($narrowed->send_after !== null) {
                $body['send_after']         = $narrowed->send_after;
            }
            if ($narrowed->delayed_option !== null) {
                $body['delayed_option']     = $narrowed->delayed_option;
            }
            if ($narrowed->external_id !== null) {
                $body['external_id']        = $narrowed->external_id;
            }
            if ($narrowed->collapse_id !== null) {
                $body['collapse_id']        = $narrowed->collapse_id;
            }
        }

        return $body;
    }

    /**
     * Body-layer mapping: HTTP 200 with an `errors` field. Returns `null` when
     * `errors` is present but does not match any known invalid-recipient marker.
     *
     * @param array<string,mixed> $raw
     */
    private function mapTicketError(array $raw, int $httpStatus): ?ConnectorError
    {
        $errors = $raw['errors'] ?? null;
        if ($errors === null) {
            return null;
        }

        $firstMessage = '';
        $isInvalidRecipient = false;

        if (is_array($errors)) {
            if (array_is_list($errors)) {
                $firstMessage = isset($errors[0]) && is_string($errors[0]) ? $errors[0] : '';
                foreach ($errors as $e) {
                    if (!is_string($e)) {
                        continue;
                    }
                    $low = strtolower($e);
                    foreach (self::INVALID_RECIPIENT_MARKERS as $marker) {
                        if (str_contains($low, $marker)) {
                            $isInvalidRecipient = true;
                            break 2;
                        }
                    }
                }
            } else {
                $hasInvalidIds
                    = (isset($errors['invalid_external_user_ids']) && is_array($errors['invalid_external_user_ids']) && count($errors['invalid_external_user_ids']) > 0)
                    || (isset($errors['invalid_player_ids']) && is_array($errors['invalid_player_ids']) && count($errors['invalid_player_ids']) > 0)
                    || (isset($errors['invalid_aliases']) && is_array($errors['invalid_aliases']) && count($errors['invalid_aliases']) > 0);
                if ($hasInvalidIds) {
                    $isInvalidRecipient = true;
                    $firstMessage = json_encode($errors, JSON_UNESCAPED_SLASHES) ?: '';
                }
            }
        }

        if (!$isInvalidRecipient) {
            return null;
        }

        $message = $firstMessage !== '' ? $firstMessage : 'OneSignal invalid recipient';

        return new ConnectorError(
            providerCode: ProviderCode::InvalidRecipient,
            providerMessage: $message,
            cause: ['raw' => $raw, 'retryAfter' => null],
            statusCode: $httpStatus,
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
            $statusCode === 401                  => ProviderCode::AuthFailed,
            $statusCode === 400                  => ProviderCode::InvalidRequest,
            $statusCode === 404                  => ProviderCode::InvalidRequest,
            $statusCode === 429                  => ProviderCode::RateLimited,
            $statusCode === 500, $statusCode === 503 => ProviderCode::ProviderUnavailable,
            $statusCode >= 500                   => ProviderCode::ProviderUnavailable,
            default                              => ProviderCode::Unknown,
        };

        $baseMessage = $this->extractErrorMessage($body, $statusCode);
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
     * @param array<string,mixed>|null $body
     */
    private function extractErrorMessage(?array $body, int $statusCode): string
    {
        if (is_array($body)) {
            $errors = $body['errors'] ?? null;
            if (is_array($errors) && array_is_list($errors) && isset($errors[0]) && is_string($errors[0])) {
                return $errors[0];
            }
            if (is_array($errors) && !array_is_list($errors)) {
                $j = json_encode($errors, JSON_UNESCAPED_SLASHES);
                if ($j !== false) {
                    return $j;
                }
            }
            if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                return $body['message'];
            }
        }

        return "OneSignal HTTP {$statusCode}";
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
