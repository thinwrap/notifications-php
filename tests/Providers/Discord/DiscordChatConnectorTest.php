<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Discord;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Discord\DiscordChatConnector;
use Thinwrap\Notifications\Providers\Discord\DiscordChatSendInput;
use Thinwrap\Notifications\Providers\Discord\DiscordConfig;

final class DiscordChatConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathProviderMessageIdFromBody(): void
    {
        $payload = ['id' => 'msg-1', 'channel_id' => 'c1'];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $result = $this->buildConnector()->send(new ChatSendInput(to: null, body: 'hi'));
        $this->assertTrue($result->success);
        $this->assertSame(ChatStatus::Sent, $result->status);
        $this->assertSame('msg-1', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertStringContainsString('wait=true', (string) $request->getUri());
    }

    public function testThreadIdAppendedToQuery(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"id":"x"}'))
        );

        $input = new DiscordChatSendInput(body: 'hi', threadId: 'th-1');
        $this->buildConnector()->send($input);
        $url = (string) $this->mockClient->getRequests()[0]->getUri();
        $this->assertStringContainsString('thread_id=th-1', $url);
    }

    public function testRetryAfterBodyWinsHeader(): void
    {
        $payload = ['retry_after' => 7.5, 'message' => 'too fast'];
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '15000']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 8 seconds', (string) $err->providerMessage);
        }
    }

    public function testHeaderMsRetryAfter(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '15000']))
                ->withBody($this->factory->createStream('{}'))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertStringContainsString('Retry-After: 15 seconds', (string) $err->providerMessage);
        }
    }

    public function test404MapsAuthFailed(): void
    {
        $this->mockClient->addResponse(new Response(404));
        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::AuthFailed, $err->providerCode);
        }
    }

    private function buildConnector(): DiscordChatConnector
    {
        return new DiscordChatConnector(
            new DiscordConfig(webhookUrl: 'https://discord.com/api/webhooks/123/abc'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
