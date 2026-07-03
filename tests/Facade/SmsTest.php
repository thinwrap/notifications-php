<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Facade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\Contract\SmsConnectorInterface;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsSendResult;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
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
use Thinwrap\Notifications\Sms;

#[CoversClass(\Thinwrap\Notifications\Sms::class)]
final class SmsTest extends TestCase
{
    /**
     * @return array<string, array{NotificationProviderId, object}>
     */
    public static function validPairProvider(): array
    {
        $vonage = new VonageConfig('k', 's');

        return [
            'vonage'      => [NotificationProviderId::Vonage, $vonage],
            'twilio'      => [NotificationProviderId::Twilio, new TwilioConfig('AC', 'tok')],
            'plivo'       => [NotificationProviderId::Plivo, new PlivoConfig('MA', 'tok')],
            'sns'         => [NotificationProviderId::Sns, new SnsConfig('AKIA', 'sec', 'us-east-1')],
            'sinch'       => [NotificationProviderId::Sinch, new SinchConfig('plan', 'tok')],
            'telnyx'      => [NotificationProviderId::Telnyx, new TelnyxConfig('k')],
            'infobip'     => [NotificationProviderId::Infobip, new InfobipConfig('k', 'https://api.infobip.test')],
            'messagebird' => [NotificationProviderId::Messagebird, new MessagebirdConfig('k')],
            'textmagic'   => [NotificationProviderId::Textmagic, new TextmagicConfig('u', 'k')],
            'd7networks'  => [NotificationProviderId::D7networks, new D7networksConfig('tok')],
        ];
    }

    /**
     * @return array<string, array{NotificationProviderId, object, class-string}>
     */
    public static function wiredConnectorProvider(): array
    {
        $pairs = self::validPairProvider();
        // Per a documented design decision the Novu `nexmo` alias is dropped — Vonage is the
        // single canonical id for the Vonage/Nexmo provider.
        $expected = [
            'vonage'      => VonageSmsConnector::class,
            'twilio'      => TwilioSmsConnector::class,
            'plivo'       => PlivoSmsConnector::class,
            'sns'         => SnsSmsConnector::class,
            'sinch'       => SinchSmsConnector::class,
            'telnyx'      => TelnyxSmsConnector::class,
            'infobip'     => InfobipSmsConnector::class,
            'messagebird' => MessagebirdSmsConnector::class,
            'textmagic'   => TextmagicSmsConnector::class,
            'd7networks'  => D7networksSmsConnector::class,
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
        $sms = new Sms($id, $config);

        self::assertSame($id, $sms->providerId);
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
        $sms = new Sms($id, $config);

        $wired = (new \ReflectionProperty(Sms::class, 'connector'))->getValue($sms);

        self::assertInstanceOf($expectedConnectorClass, $wired);
    }

    /**
     * Proves `send()` delegates to the wired connector and returns its result
     * verbatim. A spy connector is injected into the
     * facade's private `$connector` slot via reflection so no live HTTP runs.
     */
    public function testSendDelegatesToWiredConnector(): void
    {
        // A later design decision added `Sms::fromConnector()`, so delegation is now isolatable.
        $result = new SmsSendResult(true, SmsStatus::Sent, 'msg-123', ['ok' => true]);
        $spy = new class ($result) implements SmsConnectorInterface {
            public ?SmsSendInput $received = null;

            public function __construct(private readonly SmsSendResult $result) {}

            public function send(SmsSendInput $input): SmsSendResult
            {
                $this->received = $input;

                return $this->result;
            }
        };

        $sms = Sms::fromConnector($spy);

        // Custom connectors carry no enum case — `providerId` is honestly null,
        // not a dummy built-in id.
        self::assertNull($sms->providerId);

        $input = new SmsSendInput('+15551234567', 'hello');
        $returned = $sms->send($input);

        self::assertSame($input, $spy->received);
        self::assertSame($result, $returned);
    }

    public function testNullProviderIdWithoutConnectorRaisesConnectorError(): void
    {
        // `providerId`/`config` became nullable to support `fromConnector()`;
        // omitting them without injecting a connector is rejected up front.
        try {
            new Sms(null);
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            self::assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    public function testNonSmsProviderRaisesConnectorError(): void
    {
        // A later design decision: facade misuse standardizes on `ConnectorError(invalid_request)`.
        try {
            new Sms(NotificationProviderId::Slack, new TwilioConfig('AC', 'tok'));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            self::assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }
}
