<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Facade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\Contract\EmailConnectorInterface;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailSendResult;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Email;
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
use Thinwrap\Notifications\Providers\Mailtrap\MailtrapMode;
use Thinwrap\Notifications\Providers\Postmark\PostmarkConfig;
use Thinwrap\Notifications\Providers\Postmark\PostmarkEmailConnector;
use Thinwrap\Notifications\Providers\Resend\ResendConfig;
use Thinwrap\Notifications\Providers\Resend\ResendEmailConnector;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridConfig;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridEmailConnector;
use Thinwrap\Notifications\Providers\Ses\SesConfig;
use Thinwrap\Notifications\Providers\Ses\SesEmailConnector;
use Thinwrap\Notifications\Providers\Sparkpost\SparkpostConfig;
use Thinwrap\Notifications\Providers\Sparkpost\SparkpostEmailConnector;

#[CoversClass(\Thinwrap\Notifications\Email::class)]
final class EmailTest extends TestCase
{
    /**
     * @return array<string, array{NotificationProviderId, object}>
     */
    public static function validPairProvider(): array
    {
        return [
            'ses'        => [NotificationProviderId::Ses, new SesConfig('us-east-1', 'AKIA', 'sec')],
            'resend'     => [NotificationProviderId::Resend, new ResendConfig('re_x', 'sender@example.com')],
            'mailgun'    => [NotificationProviderId::Mailgun, new MailgunConfig('k', 'd.com')],
            'sendgrid'   => [NotificationProviderId::Sendgrid, new SendgridConfig('SG.x', 'sender@example.com')],
            'postmark'   => [NotificationProviderId::Postmark, new PostmarkConfig('tok', 'sender@example.com')],
            'mailersend' => [NotificationProviderId::Mailersend, new MailersendConfig('k')],
            'mailtrap'   => [NotificationProviderId::Mailtrap, new MailtrapConfig('k', MailtrapMode::Production)],
            'brevo'      => [NotificationProviderId::Brevo, new BrevoConfig('k', 'sender@example.com')],
            'sparkpost'  => [NotificationProviderId::Sparkpost, new SparkpostConfig('k', 'sender@example.com')],
        ];
    }

    /**
     * @return array<string, array{NotificationProviderId, object, class-string}>
     */
    public static function wiredConnectorProvider(): array
    {
        $pairs = self::validPairProvider();
        $expected = [
            'ses'        => SesEmailConnector::class,
            'resend'     => ResendEmailConnector::class,
            'mailgun'    => MailgunEmailConnector::class,
            'sendgrid'   => SendgridEmailConnector::class,
            'postmark'   => PostmarkEmailConnector::class,
            'mailersend' => MailersendEmailConnector::class,
            'mailtrap'   => MailtrapEmailConnector::class,
            'brevo'      => BrevoEmailConnector::class,
            'sparkpost'  => SparkpostEmailConnector::class,
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
        $email = new Email($id, $config);

        self::assertSame($id, $email->providerId);
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
        $email = new Email($id, $config);

        $wired = (new \ReflectionProperty(Email::class, 'connector'))->getValue($email);

        self::assertInstanceOf($expectedConnectorClass, $wired);
    }

    /**
     * Proves `send()` delegates to the wired connector and returns its result
     * verbatim. A spy connector is injected into the
     * facade's private `$connector` slot via reflection so no live HTTP runs.
     */
    public function testSendDelegatesToWiredConnector(): void
    {
        // A later design decision added `Email::fromConnector()`, so delegation is now isolatable
        // by injecting a spy connector (no live HTTP, no reflection hacks).
        $result = new EmailSendResult(true, EmailStatus::Sent, 'msg-123', ['ok' => true]);
        $spy = new class ($result) implements EmailConnectorInterface {
            public ?EmailSendInput $received = null;

            public function __construct(private readonly EmailSendResult $result) {}

            public function send(EmailSendInput $input): EmailSendResult
            {
                $this->received = $input;

                return $this->result;
            }
        };

        $email = Email::fromConnector($spy);

        // Custom connectors carry no enum case — `providerId` is honestly null,
        // not a dummy built-in id.
        self::assertNull($email->providerId);

        $input = new EmailSendInput('from@example.com', 'to@example.com', 'Subject');
        $returned = $email->send($input);

        self::assertSame($input, $spy->received);
        self::assertSame($result, $returned);
    }

    public function testNullProviderIdWithoutConnectorRaisesConnectorError(): void
    {
        // `providerId`/`config` became nullable to support `fromConnector()`;
        // omitting them without injecting a connector is rejected up front.
        try {
            new Email(null);
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            self::assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    public function testNonEmailProviderRaisesConnectorError(): void
    {
        // A later design decision: facade misuse standardizes on `ConnectorError(invalid_request)`
        // (was native `\InvalidArgumentException`/`\AssertionError`). Passing an
        // SMS provider id falls to the `default` match arm.
        // (We cannot pass a non-email config because the parameter union
        // restricts to the 9 email config types — that mismatch is rejected at
        // the language level with `\TypeError`.)
        try {
            new Email(NotificationProviderId::Twilio, new SesConfig('us-east-1', 'AKIA', 'sec'));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            self::assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }
}
