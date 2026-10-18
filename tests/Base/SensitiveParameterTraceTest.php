<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Base;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Thinwrap\Notifications\Chat;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\Email;
use Thinwrap\Notifications\Enum\NotificationProviderId;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Fcm\FcmConfig;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridConfig;
use Thinwrap\Notifications\Providers\Slack\SlackConfig;
use Thinwrap\Notifications\Providers\Telegram\TelegramConfig;

/**
 * With `zend.exception_ignore_args=0` (the default when no php.ini is loaded)
 * PHP records call arguments in `getTrace()`, which error trackers serialize.
 * Credentials must show up there only as `SensitiveParameterValue`.
 */
final class SensitiveParameterTraceTest extends TestCase
{
    private const MARKER = 'LEAKMARKER';

    private string|false $previousIgnoreArgs = false;

    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    protected function tearDown(): void
    {
        if ($this->previousIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->previousIgnoreArgs);
        }
    }

    public function testTransportErrorTraceOmitsTokenInUrl(): void
    {
        $this->mockClient->addException($this->clientException());

        $chat = new Chat(
            NotificationProviderId::Telegram,
            new TelegramConfig(botToken: 'bot-' . self::MARKER),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $this->assertTraceRedacted(static fn() => $chat->send(new ChatSendInput(to: '@channel', body: 'hi')));
    }

    public function testTransportErrorTraceOmitsAuthorizationHeader(): void
    {
        $this->mockClient->addException($this->clientException());

        $email = new Email(
            NotificationProviderId::Sendgrid,
            new SendgridConfig(apiKey: 'key-' . self::MARKER, from: 'from@example.com'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $this->assertTraceRedacted(static fn() => $email->send(
            new EmailSendInput(from: 'from@example.com', to: 'to@example.com', subject: 'S', text: 'T'),
        ));
    }

    public function testFacadeConfigMismatchTraceOmitsWebhookUrl(): void
    {
        $this->assertTraceRedacted(static fn() => new Chat(
            NotificationProviderId::Telegram,
            new SlackConfig(webhookUrl: 'https://hooks.slack.com/services/T000/B000/' . self::MARKER),
        ));
    }

    public function testInvalidServiceAccountTraceOmitsPrivateKey(): void
    {
        // client_email missing → construction-time validation throws.
        $this->assertTraceRedacted(static fn() => new FcmConfig(serviceAccount: [
            'project_id'  => 'p',
            'private_key' => 'pem-' . self::MARKER,
        ]));
    }

    private function clientException(): ClientExceptionInterface
    {
        return new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
    }

    private function assertTraceRedacted(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            // Only the library's own frames: runner frames further up carry
            // this TestCase (and the mock client's recorded requests) as args.
            $libraryFrames = array_values(array_filter(
                $err->getTrace(),
                static fn(array $frame): bool => isset($frame['class'])
                    && str_starts_with($frame['class'], 'Thinwrap\\Notifications\\')
                    && !str_starts_with($frame['class'], 'Thinwrap\\Notifications\\Tests\\'),
            ));
            $this->assertNotSame([], $libraryFrames);
            $trace = print_r($libraryFrames, true);
            $this->assertStringNotContainsString(self::MARKER, $trace);
            // Arguments were captured (not globally ignored) and redacted.
            $this->assertStringContainsString('SensitiveParameterValue', $trace);
        }
    }
}
