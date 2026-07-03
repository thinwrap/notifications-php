<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Line;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Line\LineChatConnector;
use Thinwrap\Notifications\Providers\Line\LineChatSendInput;
use Thinwrap\Notifications\Providers\Line\LineConfig;

final class LineChatConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testSynthesizesTextMessageFromBaseBody(): void
    {
        $payload = ['sentMessages' => [['id' => '12345678901234']]];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $result = $this->buildConnector()->send(new ChatSendInput(to: 'U123', body: 'hello'));

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['type' => 'text', 'text' => 'hello']], $decoded['messages']);
        $this->assertSame('U123', $decoded['to']);
        $this->assertTrue($result->success);
        $this->assertSame(ChatStatus::Sent, $result->status);
        $this->assertSame('12345678901234', $result->providerMessageId);
    }

    public function testExplicitMessagesArrayOverridesBody(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{}'))
        );

        $stickerMsg = [['type' => 'sticker', 'packageId' => '446', 'stickerId' => '1988']];
        $input = new LineChatSendInput(
            to: 'U123',
            body: 'ignored',
            messages: $stickerMsg,
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($stickerMsg, $decoded['messages']);
    }

    public function testFlexMessageRoundtrip(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{}'))
        );

        $flex = [['type' => 'flex', 'altText' => 'Order', 'contents' => ['type' => 'bubble', 'body' => []]]];
        $this->buildConnector()->send(new LineChatSendInput(to: 'U1', messages: $flex));

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('flex', $decoded['messages'][0]['type']);
        $this->assertSame('Order', $decoded['messages'][0]['altText']);
        $this->assertSame('bubble', $decoded['messages'][0]['contents']['type']);
    }

    public function testAuthFailedOn401(): void
    {
        $this->mockClient->addResponse(new Response(401, ['Content-Type' => 'application/json']));
        try {
            $this->buildConnector()->send(new ChatSendInput(to: 'U1', body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::AuthFailed, $err->providerCode);
        }
    }

    public function testInvalidRecipientOn400NotFound(): void
    {
        $payload = ['message' => 'The user is not found, or not yet a friend of the bot account.'];
        $this->mockClient->addResponse(
            (new Response(400, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: 'U-bad', body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    public function testRateLimitedRetryAfterSurfaced(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '30']))
                ->withBody($this->factory->createStream('{"message":"rate"}'))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: 'U1', body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 30 seconds', (string) $err->providerMessage);
            /** @var array<string,mixed> $cause */
            $cause = $err->cause;
            $this->assertSame('30', $cause['retryAfter'] ?? null);
        }
    }

    public function testMissingToThrowsInvalidRequest(): void
    {
        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            $this->assertSame(0, count($this->mockClient->getRequests()));
        }
    }

    public function testPassthroughHonored(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{}'))
        );

        $input = new ChatSendInput(
            to: 'U1',
            body: 'hi',
            _passthrough: [
                'body' => ['notificationDisabled' => true],
                'headers' => ['X-Line-Trace' => 't'],
            ],
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($decoded['notificationDisabled']);
        $this->assertSame('t', $request->getHeaderLine('X-Line-Trace'));
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    }

    private function buildConnector(): LineChatConnector
    {
        return new LineChatConnector(
            new LineConfig(channelAccessToken: 'test-token'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
