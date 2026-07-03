<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Mailersend;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Mailersend\MailersendConfig;
use Thinwrap\Notifications\Providers\Mailersend\MailersendEmailConnector;

final class MailersendEmailConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathReturnsMessageIdFromHeader(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'msg-1']));
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertSame('msg-1', $result->providerMessageId);
        $this->assertSame(EmailStatus::Queued, $result->status);
        $this->assertTrue($result->success);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('XMLHttpRequest', $request->getHeaderLine('X-Requested-With'));
    }

    public function testEmptyMessageIdHeaderCoercesToNull(): void
    {
        $this->mockClient->addResponse(new Response(202));
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertNull($result->providerMessageId);
    }

    public function testFromObjectShapeWithSenderName(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = new MailersendEmailConnector(
            new MailersendConfig(apiToken: 't', senderName: 'Alice'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $input = new EmailSendInput(
            from: 'alice@x',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['email' => 'alice@x', 'name' => 'Alice'], $decoded['from']);
    }

    public function testFromObjectShapeWithoutSenderName(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'alice@x',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['email' => 'alice@x'], $decoded['from']);
    }

    public function testTagsTruncatedToFive(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            tags: ['a', 'b', 'c', 'd', 'e', 'f'],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(5, $decoded['tags']);
        $this->assertSame('e', $decoded['tags'][4]);
    }

    public function testAttachmentBase64AndContentId(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();
        $attachment = new EmailAttachment(
            filename: 'file.pdf',
            contentType: 'application/pdf',
            content: 'data',
            contentId: 'cid1',
        );
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            attachments: [$attachment],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $att = $decoded['attachments'][0];
        $this->assertSame(base64_encode('data'), $att['content']);
        $this->assertSame('cid1', $att['id']);
        $this->assertSame('inline', $att['disposition']);
    }

    public function testPassthroughBodyHonored(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            _passthrough: [
                'body' => ['template_id' => 'tpl_1', 'send_at' => 1234567890],
            ],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('tpl_1', $decoded['template_id']);
        $this->assertSame(1234567890, $decoded['send_at']);
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['message' => 'auth'], [], ProviderCode::AuthFailed, null];
        yield '422 recipient field' => [422, ['message' => 'invalid', 'errors' => ['to.0.email' => ['bad']]], [], ProviderCode::InvalidRecipient, null];
        yield '422 other field' => [422, ['message' => 'invalid', 'errors' => ['subject' => ['required']]], [], ProviderCode::InvalidRequest, null];
        yield '429 retry-after' => [429, ['message' => 'rate'], ['Retry-After' => '30'], ProviderCode::RateLimited, '30'];
        yield '500' => [500, ['message' => 'oops'], [], ProviderCode::ProviderUnavailable, null];
    }

    #[DataProvider('errorScenarios')]
    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    public function testErrorMapping(int $status, array $body, array $headers, ProviderCode $expected, ?string $expectedRetryAfter): void
    {
        $headersWithCt = array_merge(['Content-Type' => 'application/json'], $headers);
        $this->mockClient->addResponse(
            (new Response($status, $headersWithCt))
                ->withBody($this->factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame($expected, $err->providerCode);
            if ($expectedRetryAfter !== null) {
                $this->assertStringContainsString("Retry-After: {$expectedRetryAfter} seconds", (string) $err->providerMessage);
                /** @var array<string,mixed> $cause */
                $cause = $err->cause;
                $this->assertSame($expectedRetryAfter, $cause['retryAfter'] ?? null);
            }
        }
    }

    private function basicInput(): EmailSendInput
    {
        return new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
        );
    }

    private function buildConnector(): MailersendEmailConnector
    {
        return new MailersendEmailConnector(
            new MailersendConfig(apiToken: 'test-token'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
