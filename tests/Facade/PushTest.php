<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Facade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\Contract\PushConnectorInterface;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushSendResult;
use Thinwrap\Notifications\DTO\Push\PushStatus;
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
use Thinwrap\Notifications\Push;

#[CoversClass(\Thinwrap\Notifications\Push::class)]
final class PushTest extends TestCase
{
    /**
     * @return array<string, array{NotificationProviderId, object}>
     */
    public static function validPairProvider(): array
    {
        $serviceAccount = [
            'type'           => 'service_account',
            'project_id'     => 'p',
            'private_key_id' => 'k',
            'private_key'    => 'PEM',
            'client_email'   => 'x@y.iam.gserviceaccount.com',
        ];

        return [
            'fcm'         => [NotificationProviderId::Fcm, new FcmConfig($serviceAccount)],
            'expo'        => [NotificationProviderId::Expo, new ExpoConfig()],
            'apns'        => [NotificationProviderId::Apns, new ApnsConfig('KID', 'TID', 'com.app', 'PEM')],
            'onesignal'   => [NotificationProviderId::OneSignal, new OneSignalConfig('app', 'key')],
            'pusherbeams' => [NotificationProviderId::PusherBeams, new PusherBeamsConfig('iid', 'sec')],
            'wonderpush'  => [NotificationProviderId::Wonderpush, new WonderpushConfig('app', 'tok')],
        ];
    }

    /**
     * @return array<string, array{NotificationProviderId, object, class-string}>
     */
    public static function wiredConnectorProvider(): array
    {
        $pairs = self::validPairProvider();
        $expected = [
            'fcm'         => FcmPushConnector::class,
            'expo'        => ExpoPushConnector::class,
            'apns'        => ApnsPushConnector::class,
            'onesignal'   => OneSignalPushConnector::class,
            'pusherbeams' => PusherBeamsPushConnector::class,
            'wonderpush'  => WonderpushPushConnector::class,
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
        $push = new Push($id, $config);

        self::assertSame($id, $push->providerId);
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
        $push = new Push($id, $config);

        $wired = (new \ReflectionProperty(Push::class, 'connector'))->getValue($push);

        self::assertInstanceOf($expectedConnectorClass, $wired);
    }

    /**
     * Proves `send()` delegates to the wired connector and returns its result
     * verbatim. A spy connector is injected into the
     * facade's private `$connector` slot via reflection so no live HTTP runs.
     */
    public function testSendDelegatesToWiredConnector(): void
    {
        // A later design decision added `Push::fromConnector()`, so delegation is now isolatable.
        $result = new PushSendResult(true, PushStatus::Sent, 'msg-123', ['ok' => true]);
        $spy = new class ($result) implements PushConnectorInterface {
            public ?PushSendInput $received = null;

            public function __construct(private readonly PushSendResult $result) {}

            public function send(PushSendInput $input): PushSendResult
            {
                $this->received = $input;

                return $this->result;
            }
        };

        $push = Push::fromConnector($spy);

        // Custom connectors carry no enum case — `providerId` is honestly null,
        // not a dummy built-in id.
        self::assertNull($push->providerId);

        $input = new PushSendInput('device-token', 'Title', 'Body');
        $returned = $push->send($input);

        self::assertSame($input, $spy->received);
        self::assertSame($result, $returned);
    }

    public function testNullProviderIdWithoutConnectorRaisesConnectorError(): void
    {
        // `providerId`/`config` became nullable to support `fromConnector()`;
        // omitting them without injecting a connector is rejected up front.
        try {
            new Push(null);
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            self::assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    public function testNonPushProviderRaisesConnectorError(): void
    {
        // A later design decision: facade misuse standardizes on `ConnectorError(invalid_request)`.
        try {
            new Push(NotificationProviderId::Twilio, new ExpoConfig());
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            self::assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }
}
