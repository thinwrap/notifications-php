<?php

declare(strict_types=1);

namespace Thinwrap\Notifications;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Contract\ChatConnectorInterface;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatSendResult;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Discord\DiscordChatConnector;
use Thinwrap\Notifications\Providers\Discord\DiscordConfig;
use Thinwrap\Notifications\Providers\GoogleChat\GoogleChatChatConnector;
use Thinwrap\Notifications\Providers\GoogleChat\GoogleChatConfig;
use Thinwrap\Notifications\Providers\Line\LineChatConnector;
use Thinwrap\Notifications\Providers\Line\LineConfig;
use Thinwrap\Notifications\Providers\Mattermost\MattermostChatConnector;
use Thinwrap\Notifications\Providers\Mattermost\MattermostConfig;
use Thinwrap\Notifications\Providers\Msteams\MsteamsChatConnector;
use Thinwrap\Notifications\Providers\Msteams\MsteamsConfig;
use Thinwrap\Notifications\Providers\RocketChat\RocketChatChatConnector;
use Thinwrap\Notifications\Providers\RocketChat\RocketChatConfig;
use Thinwrap\Notifications\Providers\Slack\SlackChatConnector;
use Thinwrap\Notifications\Providers\Slack\SlackConfig;
use Thinwrap\Notifications\Providers\Telegram\TelegramChatConnector;
use Thinwrap\Notifications\Providers\Telegram\TelegramConfig;
use Thinwrap\Notifications\Providers\WhatsappBusiness\WhatsappBusinessChatConnector;
use Thinwrap\Notifications\Providers\WhatsappBusiness\WhatsappBusinessConfig;

/**
 * Unified Chat facade. Mirrors TS `Chat<P>` (notifications-ts/src/facades/chat.facade.ts).
 *
 * Two distinct auth shapes split the 9 chat providers:
 * - Webhook-URL-as-auth (5): Slack, Discord, Msteams, Mattermost, GoogleChat.
 * - API-token auth (4): Telegram, WhatsappBusiness, RocketChat, Line.
 *
 * The base `ChatSendInput.to` is nullable to support webhook-URL providers;
 * token-auth connectors validate non-null themselves before HTTP.
 *
 * A per-arm `instanceof` guard is the single runtime gate; a BYO PSR-18
 * `$client` (+ optional PSR-17 factories) is forwarded to the connector, the
 * PHP analogue of TS's `config.fetch` (bring-your-own transport).
 *
 * Non-chat provider ids raise `ConnectorError(invalid_request)`.
 */
final class Chat implements ChatConnectorInterface
{
    /** Channel discriminator, parity with TS `Chat<P>.channelType`. */
    public readonly string $channelType;

    /** `null` when built via {@see self::fromConnector()} with a custom connector. */
    public readonly ?NotificationProviderId $providerId;

    private readonly ChatConnectorInterface $connector;

    /**
     * Prefer {@see self::fromConnector()} to inject a pre-built connector.
     */
    public function __construct(
        ?NotificationProviderId $providerId,
        #[\SensitiveParameter]
        TelegramConfig|SlackConfig|WhatsappBusinessConfig|DiscordConfig|MsteamsConfig|GoogleChatConfig|MattermostConfig|RocketChatConfig|LineConfig|null $config = null,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        #[\SensitiveParameter]
        ?ChatConnectorInterface $connector = null,
    ) {
        $this->providerId = $providerId;
        $this->channelType = 'chat';

        if ($connector !== null) {
            $this->connector = $connector;

            return;
        }

        if ($providerId === null || $config === null) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Chat facade requires a provider id and config when no connector is injected',
            );
        }

        $this->connector = match ($providerId) {
            NotificationProviderId::Telegram => $config instanceof TelegramConfig
                ? new TelegramChatConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'TelegramConfig'),
            NotificationProviderId::Slack => $config instanceof SlackConfig
                ? new SlackChatConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'SlackConfig'),
            NotificationProviderId::WhatsappBusiness => $config instanceof WhatsappBusinessConfig
                ? new WhatsappBusinessChatConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'WhatsappBusinessConfig'),
            NotificationProviderId::Discord => $config instanceof DiscordConfig
                ? new DiscordChatConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'DiscordConfig'),
            NotificationProviderId::Msteams => $config instanceof MsteamsConfig
                ? new MsteamsChatConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'MsteamsConfig'),
            NotificationProviderId::GoogleChat => $config instanceof GoogleChatConfig
                ? new GoogleChatChatConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'GoogleChatConfig'),
            NotificationProviderId::Mattermost => $config instanceof MattermostConfig
                ? new MattermostChatConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'MattermostConfig'),
            NotificationProviderId::RocketChat => $config instanceof RocketChatConfig
                ? new RocketChatChatConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'RocketChatConfig'),
            NotificationProviderId::Line => $config instanceof LineConfig
                ? new LineChatConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'LineConfig'),
            default => throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Provider {$providerId->value} is not a chat provider",
            ),
        };
    }

    /**
     * Build the facade from a pre-constructed chat connector. Mirrors TS's
     * `new Chat(connector)` overload.
     */
    public static function fromConnector(#[\SensitiveParameter] ChatConnectorInterface $connector): self
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

    public function send(ChatSendInput $input): ChatSendResult
    {
        return $this->connector->send($input);
    }
}
