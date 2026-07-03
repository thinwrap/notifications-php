<?php

declare(strict_types=1);

namespace Thinwrap\Notifications;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Contract\EmailConnectorInterface;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailSendResult;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Brevo\BrevoConfig;
use Thinwrap\Notifications\Providers\Brevo\BrevoEmailConnector;
use Thinwrap\Notifications\Providers\Mailersend\MailersendConfig;
use Thinwrap\Notifications\Providers\Mailersend\MailersendEmailConnector;
use Thinwrap\Notifications\Providers\Mailgun\MailgunConfig;
use Thinwrap\Notifications\Providers\Mailgun\MailgunEmailConnector;
use Thinwrap\Notifications\Providers\Mailtrap\MailtrapConfig;
use Thinwrap\Notifications\Providers\Mailtrap\MailtrapEmailConnector;
use Thinwrap\Notifications\Providers\Postmark\PostmarkConfig;
use Thinwrap\Notifications\Providers\Postmark\PostmarkEmailConnector;
use Thinwrap\Notifications\Providers\Resend\ResendConfig;
use Thinwrap\Notifications\Providers\Resend\ResendEmailConnector;
use Thinwrap\Notifications\Providers\Scaleway\ScalewayConfig;
use Thinwrap\Notifications\Providers\Scaleway\ScalewayEmailConnector;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridConfig;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridEmailConnector;
use Thinwrap\Notifications\Providers\Ses\SesConfig;
use Thinwrap\Notifications\Providers\Ses\SesEmailConnector;
use Thinwrap\Notifications\Providers\Sparkpost\SparkpostConfig;
use Thinwrap\Notifications\Providers\Sparkpost\SparkpostEmailConnector;

/**
 * Unified Email facade.
 *
 * Mirrors TS `Email<P>` (notifications-ts/src/facades/email.facade.ts). The
 * constructor accepts a `NotificationProviderId` enum case + a union of every
 * email-provider config; a per-arm `instanceof` guard is the single runtime
 * gate, and a BYO PSR-18 `$client` (+ optional PSR-17 factories) is forwarded
 * to the connector, the PHP analogue of TS's `config.fetch` (bring-your-own transport).
 *
 * Non-email provider ids (SMS, push, chat) raise `ConnectorError(invalid_request)`.
 */
final class Email implements EmailConnectorInterface
{
    /** Channel discriminator, parity with TS `Email<P>.channelType`. */
    public readonly string $channelType;

    /** `null` when built via {@see self::fromConnector()} with a custom connector. */
    public readonly ?NotificationProviderId $providerId;

    private readonly EmailConnectorInterface $connector;

    /**
     * Prefer {@see self::fromConnector()} to inject a pre-built connector.
     */
    public function __construct(
        ?NotificationProviderId $providerId,
        SesConfig|ResendConfig|MailgunConfig|SendgridConfig|PostmarkConfig|MailersendConfig|MailtrapConfig|BrevoConfig|SparkpostConfig|ScalewayConfig|null $config = null,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?EmailConnectorInterface $connector = null,
    ) {
        $this->providerId = $providerId;
        $this->channelType = 'email';

        if ($connector !== null) {
            $this->connector = $connector;

            return;
        }

        if ($providerId === null || $config === null) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Email facade requires a provider id and config when no connector is injected',
            );
        }

        $this->connector = match ($providerId) {
            NotificationProviderId::Ses => $config instanceof SesConfig
                ? new SesEmailConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'SesConfig'),
            NotificationProviderId::Resend => $config instanceof ResendConfig
                ? new ResendEmailConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'ResendConfig'),
            NotificationProviderId::Mailgun => $config instanceof MailgunConfig
                ? new MailgunEmailConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'MailgunConfig'),
            NotificationProviderId::Sendgrid => $config instanceof SendgridConfig
                ? new SendgridEmailConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'SendgridConfig'),
            NotificationProviderId::Postmark => $config instanceof PostmarkConfig
                ? new PostmarkEmailConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'PostmarkConfig'),
            NotificationProviderId::Mailersend => $config instanceof MailersendConfig
                ? new MailersendEmailConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'MailersendConfig'),
            NotificationProviderId::Mailtrap => $config instanceof MailtrapConfig
                ? new MailtrapEmailConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'MailtrapConfig'),
            NotificationProviderId::Brevo => $config instanceof BrevoConfig
                ? new BrevoEmailConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'BrevoConfig'),
            NotificationProviderId::Sparkpost => $config instanceof SparkpostConfig
                ? new SparkpostEmailConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'SparkpostConfig'),
            NotificationProviderId::Scaleway => $config instanceof ScalewayConfig
                ? new ScalewayEmailConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'ScalewayConfig'),
            default => throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Provider {$providerId->value} is not an email provider",
            ),
        };
    }

    /**
     * Build the facade from a pre-constructed email connector. Mirrors TS's
     * `new Email(connector)` overload.
     */
    public static function fromConnector(EmailConnectorInterface $connector): self
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

    public function send(EmailSendInput $input): EmailSendResult
    {
        return $this->connector->send($input);
    }
}
