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
        #[\SensitiveParameter]
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
        // The instance id is interpolated into the host and the path of a
        // request that carries the secret key, so it must be one DNS label.
        // Beams SDKs do not require a UUID, so neither does this check.
        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $this->config->instanceId) !== 1) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Pusher Beams instanceId must be a single DNS label (letters, digits, hyphens)',
                statusCode: 400,
            );
        }
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

        $url = "https://{$this->config->instanceId}.pushnotifications.pusher.com/publish_api/v1/instances/" . rawurlencode($this->config->instanceId) . "/publishes/{$suffix}";

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

        // FCM payload. An empty `notification` would encode as `[]`, which
        // FCM rejects (it must be an object), so a data-only push omits it.
        $fcm = $notification !== [] ? ['notification' => $notification] : [];
        if ($input->data !== null) {
            $fcm['data'] = self::stringifyValues($input->data);
        }
        $ttl = $narrowed?->ttl;
        if ($ttl !== null) {
            $fcm['android'] = ['ttl' => "{$ttl}s"];
        }

        // APNs payload. APNs `alert` must be a string or dictionary; a
        // data-only push has none and is a background push, which Apple
        // defines as "an `aps` dictionary that includes only the
        // `content-available` key".
        $aps = $notification !== [] ? ['alert' => $notification] : ['content-available' => 1];
        if ($narrowed !== null) {
            if ($narrowed->badge !== null) {
                $aps['badge'] = $narrowed->badge;
            }
            if ($narrowed->sound !== null) {
                $aps['sound'] = $narrowed->sound;
            }
        }
        $apns = ['aps' => $aps];
        // Beams publish payloads put custom iOS data beside `aps`:
        // `apns: { aps: { alert: {…} }, data: {…} }`.
        if ($input->data !== null) {
            $apns['data'] = $input->data;
        }
        if ($ttl !== null) {
            $apns['apns-expiration'] = time() + $ttl;
        }

        // Web payload — preserves original data types.
        $web = $notification !== [] ? ['notification' => $notification] : [];
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

        // A push with neither notification nor data has nothing for FCM or
        // web; an empty block would encode as `[]`, not an object.
        if ($wire['fcm'] === []) {
            unset($wire['fcm']);
        }
        if ($wire['web'] === []) {
            unset($wire['web']);
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
        // Beams publish API status table: 400 bad request, 401 "Incorrect API
        // Key", 402 "Publishing has been blocked due to being over plan limits"
        // (a quota), 404 "Could not find the instance" (a wrong instanceId in
        // the request, not the recipient), 422 "JSON does not match our
        // schema" (the request, not the user), 429 "Too many requests", 500
        // internal error.
        $providerCode = match (true) {
            $statusCode === 401, $statusCode === 403 => ProviderCode::AuthFailed,
            $statusCode === 402, $statusCode === 429 => ProviderCode::RateLimited,
            $statusCode >= 500                   => ProviderCode::ProviderUnavailable,
            $statusCode >= 400                   => ProviderCode::InvalidRequest,
            default                              => ProviderCode::Unknown,
        };

        $baseMessage = "Pusher Beams {$statusCode}";
        $error = is_array($body) && isset($body['error']) && is_string($body['error']) ? $body['error'] : '';
        $description = is_array($body) && isset($body['description']) && is_string($body['description']) ? $body['description'] : '';
        if ($error !== '' || $description !== '') {
            $baseMessage = $error !== '' && $description !== '' ? "{$error}: {$description}" : $error . $description;
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
