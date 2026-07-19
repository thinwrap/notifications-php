<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Resend;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Resend\ResendConfig;
use Thinwrap\Notifications\Providers\Resend\ResendEmailConnector;
use Thinwrap\Notifications\Providers\Resend\ResendNarrowedInput;

final class ResendEmailConnectorTest extends TestCase
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
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode(['id' => 'r_abc'], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Sent, $result->status);
        $this->assertSame('r_abc', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.resend.com/emails', (string) $request->getUri());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('sender@example.com', $decoded['from']);
        $this->assertSame(['recipient@example.com'], $decoded['to']);
        $this->assertSame('Test', $decoded['subject']);
    }

    public function testReplyToHandMapping(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            replyTo: 'hi@x.com',
        );

        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['hi@x.com'], $decoded['reply_to']);
        $this->assertArrayNotHasKey('replyTo', $decoded);
    }

    public function testTagsHandMapping(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            tags: ['promo'],
        );

        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['name' => 'tag', 'value' => 'promo']], $decoded['tags']);
    }

    public function testAttachmentsBase64(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            attachments: [
                new \Thinwrap\Notifications\DTO\Email\EmailAttachment(
                    filename: 'file.txt',
                    contentType: 'text/plain',
                    content: 'hello',
                ),
            ],
        );

        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(base64_encode('hello'), $decoded['attachments'][0]['content']);
        $this->assertSame('text/plain', $decoded['attachments'][0]['content_type']);
    }

    public function testSenderNameComposition(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new ResendEmailConnector(
            new ResendConfig(apiKey: 'k', from: 'no-reply@acme.io', senderName: 'Acme'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        // Per-message $input->from wins over config->from; senderName still composes.
        $this->assertSame('Acme <sender@example.com>', $decoded['from']);
    }

    public function testIdempotencyKeyHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new ResendNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            idempotencyKey: 'k_1',
        );

        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('k_1', $request->getHeaderLine('Idempotency-Key'));
    }

    public function testPassthroughHonored(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            _passthrough: [
                'body' => ['custom' => 'x'],
                'headers' => ['X-Trace-Id' => 't-1'],
            ],
        );

        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('x', $decoded['custom']);
        $this->assertSame('t-1', $request->getHeaderLine('X-Trace-Id'));
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['name' => 'missing_api_key', 'message' => 'missing'], [], ProviderCode::AuthFailed, null];
        yield '403' => [403, ['message' => 'forbidden'], [], ProviderCode::AuthFailed, null];
        // Regression: Resend returns 401 with name `validation_error` for a bad/missing
        // key; the 401 status must win over the name-layer → auth_failed (not invalid_request).
        yield '401 validation_error (bad key)' => [401, ['name' => 'validation_error', 'message' => 'API key is invalid'], [], ProviderCode::AuthFailed, null];
        yield '422 recipient' => [422, ['name' => 'validation_error', 'message' => 'recipient malformed'], [], ProviderCode::InvalidRecipient, null];
        yield '422 other' => [422, ['name' => 'validation_error', 'message' => 'missing subject'], [], ProviderCode::InvalidRequest, null];
        yield '400' => [400, ['message' => 'bad'], [], ProviderCode::InvalidRequest, null];
        yield '429 with Retry-After' => [429, ['message' => 'rate'], ['Retry-After' => '60'], ProviderCode::RateLimited, '60'];
        yield '500' => [500, ['message' => 'oops'], [], ProviderCode::ProviderUnavailable, null];
        yield '418' => [418, ['message' => 'teapot'], [], ProviderCode::Unknown, null];
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

    private function buildConnector(): ResendEmailConnector
    {
        return new ResendEmailConnector(
            new ResendConfig(apiKey: 'test-key', from: 'sender@example.com'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return (new Response(200, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode(['id' => 'r_abc'], JSON_THROW_ON_ERROR)));
    }
}
