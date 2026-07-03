<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Postmark;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Postmark\PostmarkConfig;
use Thinwrap\Notifications\Providers\Postmark\PostmarkEmailConnector;

final class PostmarkEmailConnectorTest extends TestCase
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
                ->withBody($this->factory->createStream(json_encode([
                    'MessageID' => 'abc',
                    'ErrorCode' => 0,
                    'Message' => 'OK',
                    'To' => 'r@x',
                    'SubmittedAt' => '2026-05-01T00:00:00Z',
                ], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Sent, $result->status);
        $this->assertSame('abc', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('test-token', $request->getHeaderLine('X-Postmark-Server-Token'));
        $this->assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testSoftRejectOn200WithErrorCode(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode([
                    'MessageID' => 'soft',
                    'ErrorCode' => 300,
                    'Message' => 'Inactive recipient',
                ], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertFalse($result->success);
        $this->assertSame(EmailStatus::Rejected, $result->status);
        /** @var array<string,mixed> $raw */
        $raw = $result->raw;
        $this->assertSame('Inactive recipient', $raw['Message']);
    }

    public function testSingleTagNormalizationThreeTags(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            tags: ['a', 'b', 'c'],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('a', $decoded['Tag']);
        $this->assertArrayNotHasKey('Tags', $decoded);
    }

    public function testNoTags(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());
        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('Tag', $decoded);
    }

    public function testHeadersRecordToArray(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            headers: ['X-Foo' => '1', 'X-Bar' => '2'],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [['Name' => 'X-Foo', 'Value' => '1'], ['Name' => 'X-Bar', 'Value' => '2']],
            $decoded['Headers'],
        );
    }

    public function testMessageStreamPropagation(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new PostmarkEmailConnector(
            new PostmarkConfig(serverToken: 't', from: 's@x', messageStream: 'broadcasts'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('broadcasts', $decoded['MessageStream']);
    }

    public function testPassthroughHonored(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            _passthrough: [
                'body' => ['Metadata' => ['x' => 'y']],
                'headers' => ['X-Idempotency-Key' => 'idem-1'],
            ],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['x' => 'y'], $decoded['Metadata']);
        $this->assertSame('idem-1', $request->getHeaderLine('X-Idempotency-Key'));
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401 BadOrMissingApiToken' => [401, ['ErrorCode' => 10, 'Message' => 'Bad token'], [], ProviderCode::AuthFailed, null];
        yield '422 InactiveRecipient' => [422, ['ErrorCode' => 300, 'Message' => 'Inactive'], [], ProviderCode::InvalidRecipient, null];
        yield '422 InvalidEmailRequest' => [422, ['ErrorCode' => 1003, 'Message' => 'Invalid'], [], ProviderCode::InvalidRequest, null];
        yield '429 retry-after' => [429, ['Message' => 'rate'], ['Retry-After' => '60'], ProviderCode::RateLimited, '60'];
        yield '500' => [500, ['Message' => 'oops'], [], ProviderCode::ProviderUnavailable, null];
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
            text: 'Body',
        );
    }

    private function buildConnector(): PostmarkEmailConnector
    {
        return new PostmarkEmailConnector(
            new PostmarkConfig(serverToken: 'test-token', from: 'sender@example.com'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return (new Response(200, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode([
                'MessageID' => 'abc',
                'ErrorCode' => 0,
                'Message' => 'OK',
            ], JSON_THROW_ON_ERROR)));
    }
}
