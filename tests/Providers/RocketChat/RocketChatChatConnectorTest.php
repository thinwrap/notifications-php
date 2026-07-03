<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\RocketChat;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\RocketChat\RocketChatChatConnector;
use Thinwrap\Notifications\Providers\RocketChat\RocketChatChatSendInput;
use Thinwrap\Notifications\Providers\RocketChat\RocketChatConfig;

final class RocketChatChatConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testSuccessReturnsProviderMessageId(): void
    {
        $payload = ['success' => true, 'message' => ['_id' => 'msg-abc', 'rid' => 'r1']];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $result = $this->buildConnector()->send(new ChatSendInput(to: '#alerts', body: 'hi'));

        $this->assertTrue($result->success);
        $this->assertSame(ChatStatus::Sent, $result->status);
        $this->assertSame('msg-abc', $result->providerMessageId);
    }

    public function testSoftRejectReturnsRejectedStatusNoThrow(): void
    {
        $payload = ['success' => false, 'error' => 'invalid-channel'];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $result = $this->buildConnector()->send(new ChatSendInput(to: '#x', body: 'hi'));

        $this->assertFalse($result->success);
        $this->assertSame(ChatStatus::Rejected, $result->status);
        $this->assertNull($result->providerMessageId);
    }

    public function testTwoHeaderAuthPair(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{"success":true}'))
        );

        $this->buildConnector()->send(new ChatSendInput(to: '#x', body: 'hi'));
        $request = $this->mockClient->getRequests()[0];

        $this->assertSame('tok-123', $request->getHeaderLine('X-Auth-Token'));
        $this->assertSame('uid-456', $request->getHeaderLine('X-User-Id'));
        $this->assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testEndpointAppendsRestPath(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{"success":true}'))
        );
        $this->buildConnector()->send(new ChatSendInput(to: '#x', body: 'hi'));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://chat.example.com/api/v1/chat.postMessage', (string) $request->getUri());
    }

    public function testToFieldIsChannelKey(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{"success":true}'))
        );
        $this->buildConnector()->send(new ChatSendInput(to: '#alerts', body: 'hi'));

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('#alerts', $decoded['channel']);
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['error' => 'unauth'], [], ProviderCode::AuthFailed, null];
        yield '403' => [403, ['error' => 'forbidden'], [], ProviderCode::AuthFailed, null];
        yield '400 invalid-channel' => [400, ['error' => 'no', 'errorType' => 'invalid-channel'], [], ProviderCode::InvalidRecipient, null];
        yield '400 other' => [400, ['error' => 'malformed'], [], ProviderCode::InvalidRequest, null];
        yield '429 retry-after' => [429, ['error' => 'rate'], ['Retry-After' => '5'], ProviderCode::RateLimited, '5'];
        yield '500' => [500, ['error' => 'oops'], [], ProviderCode::ProviderUnavailable, null];
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

        try {
            $this->buildConnector()->send(new ChatSendInput(to: '#x', body: 'hi'));
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

    public function testAttachmentsPassthroughVerbatim(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{"success":true}'))
        );

        $atts = [['color' => 'good', 'author_name' => 'Alice', 'title_link' => 'https://t']];
        $input = new RocketChatChatSendInput(to: '#x', body: 'hi', attachments: $atts);
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($atts[0], $decoded['attachments'][0]);
    }

    public function testPassthroughHonored(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{"success":true}'))
        );

        $input = new RocketChatChatSendInput(
            to: '#x',
            body: 'hi',
            _passthrough: [
                'body' => ['parseUrls' => false],
                'headers' => ['X-Idempotency-Key' => 'k'],
            ],
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($decoded['parseUrls']);
        $this->assertSame('k', $request->getHeaderLine('X-Idempotency-Key'));
    }

    public function testMissingToThrows(): void
    {
        $this->expectException(ConnectorError::class);
        $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
    }

    private function buildConnector(): RocketChatChatConnector
    {
        return new RocketChatChatConnector(
            new RocketChatConfig(
                baseUrl: 'https://chat.example.com',
                userId: 'uid-456',
                authToken: 'tok-123',
            ),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
