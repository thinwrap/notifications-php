<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Facade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\Contract\ChatConnectorInterface;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatSendResult;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
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

#[CoversClass(\Thinwrap\Notifications\Chat::class)]
final class ChatTest extends TestCase
{
    /**
     * @return array<string, array{NotificationProviderId, object}>
     */
    public static function validPairProvider(): array
    {
        return [
            'telegram'         => [NotificationProviderId::Telegram, new TelegramConfig('bot:tok')],
            'slack'            => [NotificationProviderId::Slack, new SlackConfig('https://hooks.slack.test/x')],
            'whatsappbusiness' => [NotificationProviderId::WhatsappBusiness, new WhatsappBusinessConfig('tok', 'pid')],
            'discord'          => [NotificationProviderId::Discord, new DiscordConfig('https://discord.test/x')],
            'msteams'          => [NotificationProviderId::Msteams, new MsteamsConfig('https://teams.test/x')],
            'googlechat'       => [NotificationProviderId::GoogleChat, new GoogleChatConfig('https://chat.google.test/x')],
            'mattermost'       => [NotificationProviderId::Mattermost, new MattermostConfig('https://mm.test/x')],
            'rocketchat'       => [NotificationProviderId::RocketChat, new RocketChatConfig('https://rc.test', 'uid', 'tok')],
            'line'             => [NotificationProviderId::Line, new LineConfig('chan-tok')],
        ];
    }

    /**
     * @return array<string, array{NotificationProviderId, object, class-string}>
     */
    public static function wiredConnectorProvider(): array
    {
        $pairs = self::validPairProvider();
        $expected = [
            'telegram'         => TelegramChatConnector::class,
            'slack'            => SlackChatConnector::class,
            'whatsappbusiness' => WhatsappBusinessChatConnector::class,
            'discord'          => DiscordChatConnector::class,
            'msteams'          => MsteamsChatConnector::class,
            'googlechat'       => GoogleChatChatConnector::class,
            'mattermost'       => MattermostChatConnector::class,
            'rocketchat'       => RocketChatChatConnector::class,
            'line'             => LineChatConnector::class,
        ];

        $cases = [];
        foreach ($pairs as $name => [$id, $config]) {
            $cases[$name] = [$id, $config, $expected[$name]];
        }

        return $cases;
    }

    #[DataProvider('validPairProvider')]
    public function testValidPairConstructs(NotificationProviderId $id, object $config): void
    {
        $chat = new Chat($id, $config);

        self::assertSame($id, $chat->providerId);
    }

    /**
     * Proves the facade wires the CONCRETE connector class for each provider id,
     * not merely echoes the provider id back.
     *
     * @param class-string $expectedConnectorClass
     */
    #[DataProvider('wiredConnectorProvider')]
    public function testWiresExpectedConnectorClass(
        NotificationProviderId $id,
        object $config,
        string $expectedConnectorClass,
    ): void {
        $chat = new Chat($id, $config);

        $wired = (new \ReflectionProperty(Chat::class, 'connector'))->getValue($chat);

        self::assertInstanceOf($expectedConnectorClass, $wired);
    }

    /**
     * Proves `send()` delegates to the wired connector and returns its result
     * verbatim. A spy connector is injected into the
     * facade's private `$connector` slot via reflection so no live HTTP runs.
     */
    public function testSendDelegatesToWiredConnector(): void
    {
        // A later design decision added `Chat::fromConnector()`, so delegation is now isolatable.
        $result = new ChatSendResult(true, ChatStatus::Sent, 'msg-123', ['ok' => true]);
        $spy = new class ($result) implements ChatConnectorInterface {
            public ?ChatSendInput $received = null;

            public function __construct(private readonly ChatSendResult $result) {}

            public function send(ChatSendInput $input): ChatSendResult
            {
                $this->received = $input;

                return $this->result;
            }
        };

        $chat = Chat::fromConnector($spy);

        // Custom connectors carry no enum case — `providerId` is honestly null,
        // not a dummy built-in id.
        self::assertNull($chat->providerId);

        $input = new ChatSendInput(null, 'hello');
        $returned = $chat->send($input);

        self::assertSame($input, $spy->received);
        self::assertSame($result, $returned);
    }

    public function testNullProviderIdWithoutConnectorRaisesConnectorError(): void
    {
        // `providerId`/`config` became nullable to support `fromConnector()`;
        // omitting them without injecting a connector is rejected up front.
        try {
            new Chat(null);
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            self::assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    public function testNonChatProviderRaisesConnectorError(): void
    {
        // A later design decision: facade misuse standardizes on `ConnectorError(invalid_request)`.
        try {
            new Chat(NotificationProviderId::Twilio, new SlackConfig('https://hooks.slack.test/x'));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            self::assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }
}
