<?php

declare(strict_types=1);

namespace Thinwrap\Notifications;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Contract\PushConnectorInterface;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushSendResult;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Apns\ApnsConfig;
use Thinwrap\Notifications\Providers\Apns\ApnsPushConnector;
use Thinwrap\Notifications\Providers\Expo\ExpoConfig;
use Thinwrap\Notifications\Providers\Expo\ExpoPushConnector;
use Thinwrap\Notifications\Providers\Fcm\FcmConfig;
use Thinwrap\Notifications\Providers\Fcm\FcmPushConnector;
use Thinwrap\Notifications\Providers\OneSignal\OneSignalConfig;
use Thinwrap\Notifications\Providers\OneSignal\OneSignalPushConnector;
use Thinwrap\Notifications\Providers\PusherBeams\PusherBeamsConfig;
use Thinwrap\Notifications\Providers\PusherBeams\PusherBeamsPushConnector;
use Thinwrap\Notifications\Providers\Wonderpush\WonderpushConfig;
use Thinwrap\Notifications\Providers\Wonderpush\WonderpushPushConnector;

/**
 * Unified Push facade. Mirrors TS `Push<P>` (notifications-ts/src/facades/push.facade.ts).
 *
 * A per-arm `instanceof` guard is the single runtime gate; a BYO PSR-18
 * `$client` (+ optional PSR-17 factories) is forwarded to the connector, the
 * PHP analogue of TS's `config.fetch` (bring-your-own transport).
 *
 * Non-push provider ids raise `ConnectorError(invalid_request)`.
 */
final class Push implements PushConnectorInterface
{
    /** Channel discriminator, parity with TS `Push<P>.channelType`. */
    public readonly string $channelType;

    /** `null` when built via {@see self::fromConnector()} with a custom connector. */
    public readonly ?NotificationProviderId $providerId;

    private readonly PushConnectorInterface $connector;

    /**
     * Prefer {@see self::fromConnector()} to inject a pre-built connector.
     */
    public function __construct(
        ?NotificationProviderId $providerId,
        #[\SensitiveParameter]
        FcmConfig|ExpoConfig|ApnsConfig|OneSignalConfig|PusherBeamsConfig|WonderpushConfig|null $config = null,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        #[\SensitiveParameter]
        ?PushConnectorInterface $connector = null,
    ) {
        $this->providerId = $providerId;
        $this->channelType = 'push';

        if ($connector !== null) {
            $this->connector = $connector;

            return;
        }

        if ($providerId === null || $config === null) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Push facade requires a provider id and config when no connector is injected',
            );
        }

        $this->connector = match ($providerId) {
            NotificationProviderId::Fcm => $config instanceof FcmConfig
                ? new FcmPushConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'FcmConfig'),
            NotificationProviderId::Expo => $config instanceof ExpoConfig
                ? new ExpoPushConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'ExpoConfig'),
            NotificationProviderId::Apns => $config instanceof ApnsConfig
                ? new ApnsPushConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'ApnsConfig'),
            NotificationProviderId::OneSignal => $config instanceof OneSignalConfig
                ? new OneSignalPushConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'OneSignalConfig'),
            NotificationProviderId::PusherBeams => $config instanceof PusherBeamsConfig
                ? new PusherBeamsPushConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'PusherBeamsConfig'),
            NotificationProviderId::Wonderpush => $config instanceof WonderpushConfig
                ? new WonderpushPushConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'WonderpushConfig'),
            default => throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Provider {$providerId->value} is not a push provider",
            ),
        };
    }

    /**
     * Build the facade from a pre-constructed push connector. Mirrors TS's
     * `new Push(connector)` overload.
     */
    public static function fromConnector(#[\SensitiveParameter] PushConnectorInterface $connector): self
    {
        return new self(null, connector: $connector);
    }

    private static function mismatch(NotificationProviderId $providerId, string $expected): ConnectorError
    {
        return new ConnectorError(
            providerCode: ProviderCode::InvalidRequest,
            providerMessage: "Provider {$providerId->value} requires {$expected}",
        );
    }

    public function send(PushSendInput $input): PushSendResult
    {
        return $this->connector->send($input);
    }
}
