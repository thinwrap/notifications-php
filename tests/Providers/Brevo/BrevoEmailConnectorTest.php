<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Brevo;

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
use Thinwrap\Notifications\Providers\Brevo\BrevoConfig;
use Thinwrap\Notifications\Providers\Brevo\BrevoEmailConnector;

final class BrevoEmailConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPath(): void
    {
        $this->mockClient->addResponse(
            (new Response(201, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode(['messageId' => 'brevo-1'], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Sent, $result->status);
        $this->assertSame('brevo-1', $result->providerMessageId);
    }

    public function testApiKeyHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('k', $request->getHeaderLine('api-key'));
        $this->assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testBodyShapeFieldRenames(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector('no-reply@acme.io', 'Acme');

        $input = new EmailSendInput(
            from: 'no-reply@acme.io',
            to: 'r@x',
            subject: 'Hi',
            cc: ['c@x'],
            bcc: ['b@x'],
            replyTo: 'rt@x',
            text: 't',
            html: '<p>h</p>',
            headers: ['X-Foo' => 'bar'],
            tags: ['t1'],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['name' => 'Acme', 'email' => 'no-reply@acme.io'], $decoded['sender']);
        $this->assertSame([['email' => 'r@x']], $decoded['to']);
        $this->assertSame([['email' => 'c@x']], $decoded['cc']);
        $this->assertSame([['email' => 'b@x']], $decoded['bcc']);
        $this->assertSame(['email' => 'rt@x'], $decoded['replyTo']);
        $this->assertSame('<p>h</p>', $decoded['htmlContent']);
        $this->assertSame('t', $decoded['textContent']);
        $this->assertSame(['t1'], $decoded['tags']);
    }

    public function testInlineCidThrowsPreFlight(): void
    {
        $connector = $this->buildConnector();
        $att = new EmailAttachment(filename: 'a.png', contentType: 'image/png', content: 'x', contentId: 'cid1');
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'r@x',
            subject: 'Hi',
            text: 'hi',
            attachments: [$att],
        );

        try {
            $connector->send($input);
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            // Pre-HTTP validation error → no HTTP status (null), not the invalid 0.
            $this->assertNull($err->statusCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    public function testAttachmentNonCidBase64Encoded(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $att = new EmailAttachment(filename: 'doc.pdf', contentType: 'application/pdf', content: 'binarydata');
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'r@x',
            subject: 'Hi',
            text: 'hi',
            attachments: [$att],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['name' => 'doc.pdf', 'content' => base64_encode('binarydata')]], $decoded['attachment']);
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['message' => 'unauth'], [], ProviderCode::AuthFailed, null];
        yield '402 credits' => [402, ['message' => 'credits exhausted'], [], ProviderCode::AuthFailed, null];
        yield '400 recipient' => [400, ['message' => 'invalid recipient'], [], ProviderCode::InvalidRecipient, null];
        yield '400 other' => [400, ['message' => 'bad subject'], [], ProviderCode::InvalidRequest, null];
        yield '429 retry' => [429, ['message' => 'rate'], ['Retry-After' => '60'], ProviderCode::RateLimited, '60'];
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
            }
        }
    }

    private function basicInput(): EmailSendInput
    {
        return new EmailSendInput(
            from: 'sender@example.com',
            to: 'r@x',
            subject: 'Hi',
            text: 'hi',
        );
    }

    private function buildConnector(string $from = 'sender@example.com', ?string $senderName = null): BrevoEmailConnector
    {
        return new BrevoEmailConnector(
            new BrevoConfig(apiKey: 'k', from: $from, senderName: $senderName),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return (new Response(201, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode(['messageId' => 'm'], JSON_THROW_ON_ERROR)));
    }
}
