<?php

declare(strict_types=1);

namespace Thinwrap\Notifications;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Contract\SmsConnectorInterface;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsSendResult;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\D7networks\D7networksConfig;
use Thinwrap\Notifications\Providers\D7networks\D7networksSmsConnector;
use Thinwrap\Notifications\Providers\Infobip\InfobipConfig;
use Thinwrap\Notifications\Providers\Infobip\InfobipSmsConnector;
use Thinwrap\Notifications\Providers\Messagebird\MessagebirdConfig;
use Thinwrap\Notifications\Providers\Messagebird\MessagebirdSmsConnector;
use Thinwrap\Notifications\Providers\Plivo\PlivoConfig;
use Thinwrap\Notifications\Providers\Plivo\PlivoSmsConnector;
use Thinwrap\Notifications\Providers\Sinch\SinchConfig;
use Thinwrap\Notifications\Providers\Sinch\SinchSmsConnector;
use Thinwrap\Notifications\Providers\Sns\SnsConfig;
use Thinwrap\Notifications\Providers\Sns\SnsSmsConnector;
use Thinwrap\Notifications\Providers\Telnyx\TelnyxConfig;
use Thinwrap\Notifications\Providers\Telnyx\TelnyxSmsConnector;
use Thinwrap\Notifications\Providers\Textmagic\TextmagicConfig;
use Thinwrap\Notifications\Providers\Textmagic\TextmagicSmsConnector;
use Thinwrap\Notifications\Providers\Twilio\TwilioConfig;
use Thinwrap\Notifications\Providers\Twilio\TwilioSmsConnector;
use Thinwrap\Notifications\Providers\Vonage\VonageConfig;
use Thinwrap\Notifications\Providers\Vonage\VonageSmsConnector;

/**
 * Unified SMS facade. Mirrors TS `Sms<P>` (notifications-ts/src/facades/sms.facade.ts).
 *
 * No provider aliases are carried: `Vonage` is the single canonical id for the
 * Vonage (formerly Nexmo) provider; there is no separate `nexmo` id.
 *
 * Non-SMS provider ids raise `ConnectorError(invalid_request)`.
 */
final class Sms implements SmsConnectorInterface
{
    /** Channel discriminator, parity with TS `Sms<P>.channelType`. */
    public readonly string $channelType;

    /** `null` when built via {@see self::fromConnector()} with a custom connector. */
    public readonly ?NotificationProviderId $providerId;

    private readonly SmsConnectorInterface $connector;

    /**
     * Build the facade from a provider id + matching config. A BYO PSR-18
     * `$client` (+ optional PSR-17 factories) is forwarded to the connector,
     * the PHP analogue of TS's `config.fetch` (bring-your-own transport).
     *
     * Prefer {@see self::fromConnector()} to inject a pre-built connector.
     */
    public function __construct(
        ?NotificationProviderId $providerId,
        VonageConfig|TwilioConfig|PlivoConfig|SnsConfig|SinchConfig|TelnyxConfig|InfobipConfig|MessagebirdConfig|TextmagicConfig|D7networksConfig|null $config = null,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?SmsConnectorInterface $connector = null,
    ) {
        $this->providerId = $providerId;
        $this->channelType = 'sms';

        if ($connector !== null) {
            $this->connector = $connector;

            return;
        }

        if ($providerId === null || $config === null) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Sms facade requires a provider id and config when no connector is injected',
            );
        }

        $this->connector = match ($providerId) {
            NotificationProviderId::Vonage => $config instanceof VonageConfig
                ? new VonageSmsConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'VonageConfig'),
            NotificationProviderId::Twilio => $config instanceof TwilioConfig
                ? new TwilioSmsConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'TwilioConfig'),
            NotificationProviderId::Plivo => $config instanceof PlivoConfig
                ? new PlivoSmsConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'PlivoConfig'),
            NotificationProviderId::Sns => $config instanceof SnsConfig
                ? new SnsSmsConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'SnsConfig'),
            NotificationProviderId::Sinch => $config instanceof SinchConfig
                ? new SinchSmsConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'SinchConfig'),
            NotificationProviderId::Telnyx => $config instanceof TelnyxConfig
                ? new TelnyxSmsConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'TelnyxConfig'),
            NotificationProviderId::Infobip => $config instanceof InfobipConfig
                ? new InfobipSmsConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'InfobipConfig'),
            NotificationProviderId::Messagebird => $config instanceof MessagebirdConfig
                ? new MessagebirdSmsConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'MessagebirdConfig'),
            NotificationProviderId::Textmagic => $config instanceof TextmagicConfig
                ? new TextmagicSmsConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'TextmagicConfig'),
            NotificationProviderId::D7networks => $config instanceof D7networksConfig
                ? new D7networksSmsConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'D7networksConfig'),
            default => throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Provider {$providerId->value} is not an SMS provider",
            ),
        };
    }

    /**
     * Build the facade from a pre-constructed SMS connector. Mirrors TS's
     * `new Sms(connector)` overload; lets callers inject a fully-wired (or test
     * double) connector without going through the config-keyed match.
     */
    public static function fromConnector(SmsConnectorInterface $connector): self
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

    public function send(SmsSendInput $input): SmsSendResult
    {
        return $this->connector->send($input);
    }
}
