<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\PusherBeams;

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
 * Pusher Beams push connector. Mirrors TS `PusherBeamsPushConnector`
 * (notifications-ts/src/providers/pusher-beams/pusher-beams.connector.ts).
 *
 * **Casing-locality outlier:** Beams requires the publisher to send BOTH
 * an FCM-formatted payload AND an APNs-formatted payload in the same request
 * (plus optionally a Web push payload). Beams' server fans those payloads
 * out to subscribed devices based on each device's registered token type.
 * The synthesis lives here, in the connector — not in shared util.
 *
 * Routing precedence:
 *   1. `interests` set → `/publishes/interests`.
 *   2. `users` set → `/publishes/users` with the supplied users array.
 *   3. Default → `/publishes/users` with `[input->to]`.
 */
final class PusherBeamsPushConnector extends BaseConnector implements PushConnectorInterface
{
    public readonly string $id;

    public function __construct(
        private readonly PusherBeamsConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'pusher-beams';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(PushSendInput $input): PushSendResult
    {
        $connectorBody = $this->synthesizeWirePayload($input);
        $connectorHeaders = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->secretKey,
        ];

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => $connectorHeaders, 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $narrowed = $input instanceof PusherBeamsPushSendInput ? $input : null;
        $suffix = $narrowed !== null && $narrowed->interests !== null ? 'interests' : 'users';

        $url = "https://{$this->config->instanceId}.pushnotifications.pusher.com/publish_api/v1/instances/{$this->config->instanceId}/publishes/{$suffix}";
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
        $providerMessageId = null;
        if (is_array($raw) && isset($raw['publishId']) && is_string($raw['publishId'])) {
            $providerMessageId = $raw['publishId'];
        }

        return new PushSendResult(
            success: true,
            status: PushStatus::Sent,
            providerMessageId: $providerMessageId,
            raw: $raw,
        );
    }

    /**
     * Synthesize the Beams wire body. Mirror of TS `synthesizeWirePayload`.
     *
     * @return array<string,mixed>
     */
    private function synthesizeWirePayload(PushSendInput $input): array
    {
        $narrowed = $input instanceof PusherBeamsPushSendInput ? $input : null;

        // Null title/body are dropped (data-only push, a documented design decision) — mirrors TS where
        // `JSON.stringify` omits `undefined` notification fields.
        $notification = array_filter(
            ['title' => $input->title, 'body' => $input->body],
            static fn(?string $v): bool => $v !== null,
        );

        // FCM payload.
        $fcm = ['notification' => $notification];
        if ($input->data !== null) {
            $fcm['data'] = self::stringifyValues($input->data);
        }
        $ttl = $narrowed?->ttl;
        if ($ttl !== null) {
            $fcm['android'] = ['ttl' => "{$ttl}s"];
        }

        // APNs payload.
        $aps = ['alert' => $notification];
        if ($narrowed !== null) {
            if ($narrowed->badge !== null) {
                $aps['badge'] = $narrowed->badge;
            }
            if ($narrowed->sound !== null) {
                $aps['sound'] = $narrowed->sound;
            }
        }
        $apns = ['aps' => $aps];
        if ($ttl !== null) {
            $apns['apns-expiration'] = time() + $ttl;
        }

        // Web payload — preserves original data types.
        $web = ['notification' => $notification];
        if ($input->data !== null) {
            $web['data'] = $input->data;
        }

        $wire = ['fcm' => $fcm, 'apns' => $apns, 'web' => $web];

        // Recipient routing.
        if ($narrowed !== null && $narrowed->interests !== null) {
            $wire['interests'] = $narrowed->interests;
        } elseif ($narrowed !== null && $narrowed->users !== null) {
            $wire['users'] = $narrowed->users;
        } else {
            $wire['users'] = [$input->to];
        }

        // Augmentation overrides — shallow merge.
        if ($narrowed !== null) {
            if ($narrowed->fcm !== null) {
                $wire['fcm'] = array_merge($wire['fcm'], $narrowed->fcm);
            }
            if ($narrowed->apns !== null) {
                $merged = array_merge($wire['apns'], $narrowed->apns);
                if (isset($narrowed->apns['aps']) && is_array($narrowed->apns['aps'])) {
                    $merged['aps'] = array_merge($wire['apns']['aps'], $narrowed->apns['aps']);
                }
                $wire['apns'] = $merged;
            }
            if ($narrowed->web !== null) {
                $wire['web'] = array_merge($wire['web'], $narrowed->web);
            }
        }

        return $wire;
    }

    /**
     * FCM wire constraint: all `data` values must be strings; non-string values
     * are JSON-stringified.
     *
     * @param array<string,mixed> $o
     * @return array<string,string>
     */
    private static function stringifyValues(array $o): array
    {
        $out = [];
        foreach ($o as $k => $v) {
            $out[(string) $k] = is_string($v)
                ? $v
                : (json_encode($v, JSON_UNESCAPED_SLASHES) ?: '');
        }

        return $out;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $providerCode = match (true) {
            $statusCode === 401                  => ProviderCode::AuthFailed,
            $statusCode === 400                  => ProviderCode::InvalidRequest,
            $statusCode === 422                  => ProviderCode::InvalidRecipient,
            $statusCode === 429                  => ProviderCode::RateLimited,
            $statusCode === 500, $statusCode === 503 => ProviderCode::ProviderUnavailable,
            default                              => ProviderCode::Unknown,
        };

        $baseMessage = "Pusher Beams {$statusCode}";
        if (is_array($body) && isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
            $baseMessage = $body['error'];
        }

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
