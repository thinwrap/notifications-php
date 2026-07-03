<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\GoogleChat;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\GoogleChat\GoogleChatChatConnector;
use Thinwrap\Notifications\Providers\GoogleChat\GoogleChatChatSendInput;
use Thinwrap\Notifications\Providers\GoogleChat\GoogleChatConfig;

final class GoogleChatChatConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testPlainTextSuccess(): void
    {
        $payload = ['name' => 'spaces/AAAA/messages/BBBB.BBBB', 'text' => 'hi'];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $connector = $this->buildConnector();
        $result = $connector->send(new ChatSendInput(to: null, body: 'hi'));

        $this->assertTrue($result->success);
        $this->assertSame(ChatStatus::Sent, $result->status);
        $this->assertSame('spaces/AAAA/messages/BBBB.BBBB', $result->providerMessageId);
    }

    public function testCardsV2WireMapping(): void
    {
        $payload = ['name' => 'spaces/X/messages/Y'];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $input = new GoogleChatChatSendInput(
            body: 'alert',
            cardsV2: [['cardId' => 'c1', 'card' => ['header' => ['title' => 'Alert']]]],
            thread: ['name' => 'spaces/A/threads/T'],
            fallbackText: 'Alert summary',
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('alert', $decoded['text']);
        $this->assertArrayHasKey('cardsV2', $decoded);
        $this->assertArrayHasKey('thread', $decoded);
        $this->assertArrayHasKey('fallbackText', $decoded);
        $this->assertArrayNotHasKey('cards_v2', $decoded);
        $this->assertArrayNotHasKey('fallback_text', $decoded);
    }

    public function testNoAuthorizationHeader(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{}'))
        );

        $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
        $request = $this->mockClient->getRequests()[0];

        $this->assertSame('', $request->getHeaderLine('Authorization'));
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401 auth' => [401, ['error' => ['message' => 'Invalid key']], [], ProviderCode::AuthFailed, null];
        yield '404 webhook-deleted' => [404, ['error' => ['message' => 'Not found']], [], ProviderCode::AuthFailed, null];
        yield '400 invalid request' => [400, ['error' => ['message' => 'Invalid card']], [], ProviderCode::InvalidRequest, null];
        yield '429 retry-after' => [429, ['error' => ['message' => 'rate']], ['Retry-After' => '60'], ProviderCode::RateLimited, '60'];
        yield '500' => [500, ['error' => ['message' => 'oops']], [], ProviderCode::ProviderUnavailable, null];
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
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
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

    public function testPassthroughHonored(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{}'))
        );

        $input = new GoogleChatChatSendInput(
            body: 'x',
            _passthrough: ['body' => ['argumentText' => 'pt'], 'headers' => ['X-Idempotency-Key' => 'k']],
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pt', $decoded['argumentText']);
        $this->assertSame('k', $request->getHeaderLine('X-Idempotency-Key'));
    }

    public function testMissingWebhookUrlThrows(): void
    {
        $this->expectException(ConnectorError::class);
        $connector = new GoogleChatChatConnector(
            new GoogleChatConfig(webhookUrl: ''),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send(new ChatSendInput(to: null, body: 'x'));
    }

    private function buildConnector(): GoogleChatChatConnector
    {
        return new GoogleChatChatConnector(
            new GoogleChatConfig(webhookUrl: 'https://chat.googleapis.com/v1/spaces/AAAA/messages?key=K&token=T'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
