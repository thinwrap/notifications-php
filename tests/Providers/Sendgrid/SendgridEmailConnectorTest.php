<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Sendgrid;

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
use Thinwrap\Notifications\Providers\Sendgrid\SendgridConfig;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridEmailConnector;

final class SendgridEmailConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPath202WithXMessageIdHeader(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'SG.abc123']));
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Queued, $result->status);
        $this->assertSame('SG.abc123', $result->providerMessageId);
    }

    public function testEmptyXMessageIdHeaderCoercedToNull(): void
    {
        $this->mockClient->addResponse(new Response(202));
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertNull($result->providerMessageId);
    }

    public function testBodyShapeNestedEnvelope(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('personalizations', $decoded);
        $this->assertArrayHasKey('from', $decoded);
        $this->assertArrayHasKey('subject', $decoded);
        $this->assertArrayHasKey('content', $decoded);
        $this->assertSame([['email' => 'recipient@example.com']], $decoded['personalizations'][0]['to']);
    }

    public function testFromComposesSenderName(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = new SendgridEmailConnector(
            new SendgridConfig(apiKey: 'k', from: 'no-reply@acme.io', senderName: 'Acme'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        // Per-message $input->from is required and wins over config->from;
        // config->senderName still supplies the display name.
        $this->assertSame('sender@example.com', $decoded['from']['email']);
        $this->assertSame('Acme', $decoded['from']['name']);
    }

    public function testToCcBccNormalization(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'a@x',
            subject: 'Test',
            cc: ['c@x'],
            bcc: ['b@x'],
            text: 'hi',
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['email' => 'a@x']], $decoded['personalizations'][0]['to']);
        $this->assertSame([['email' => 'c@x']], $decoded['personalizations'][0]['cc']);
        $this->assertSame([['email' => 'b@x']], $decoded['personalizations'][0]['bcc']);
    }

    public function testAttachmentMapping(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();

        $attachment = new EmailAttachment(
            filename: 'file.pdf',
            contentType: 'application/pdf',
            content: 'binarydata',
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
        $this->assertSame(base64_encode('binarydata'), $att['content']);
        $this->assertSame('application/pdf', $att['type']);
        $this->assertSame('cid1', $att['content_id']);
        $this->assertSame('inline', $att['disposition']);
    }

    public function testPassthroughHonored(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            _passthrough: [
                'body' => [
                    'template_id' => 'd-abc',
                    'personalizations' => [['dynamic_template_data' => ['firstName' => 'Alex']]],
                ],
            ],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('d-abc', $decoded['template_id']);
        $this->assertSame('Alex', $decoded['personalizations'][0]['dynamic_template_data']['firstName']);
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['errors' => [['message' => 'auth']]], [], ProviderCode::AuthFailed, null];
        yield '429 retry-after' => [429, ['errors' => [['message' => 'rate']]], ['Retry-After' => '60'], ProviderCode::RateLimited, '60'];
        yield '500' => [500, ['errors' => [['message' => 'oops']]], [], ProviderCode::ProviderUnavailable, null];
        yield '400 email msg' => [400, ['errors' => [['message' => 'Invalid email address']]], [], ProviderCode::InvalidRecipient, null];
        yield '400 other' => [400, ['errors' => [['message' => 'bad request']]], [], ProviderCode::InvalidRequest, null];
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

    private function buildConnector(): SendgridEmailConnector
    {
        return new SendgridEmailConnector(
            new SendgridConfig(apiKey: 'test-key', from: 'sender@example.com'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
