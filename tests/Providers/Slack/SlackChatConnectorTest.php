<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Slack;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Slack\SlackChatConnector;
use Thinwrap\Notifications\Providers\Slack\SlackChatSendInput;
use Thinwrap\Notifications\Providers\Slack\SlackConfig;

final class SlackChatConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathPlainOk(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'text/plain']))
                ->withBody($this->factory->createStream('ok'))
        );

        $result = $this->buildConnector()->send(new ChatSendInput(to: null, body: 'hi'));
        $this->assertTrue($result->success);
        $this->assertSame(ChatStatus::Sent, $result->status);
        $this->assertNull($result->providerMessageId);
        $this->assertSame('ok', $result->raw);
    }

    public function testNarrowedFields(): void
    {
        $this->mockClient->addResponse((new Response(200))->withBody($this->factory->createStream('ok')));

        $input = new SlackChatSendInput(
            body: 'hi',
            username: 'bot',
            iconEmoji: ':smile:',
            threadTs: 'ts-1',
            mrkdwn: true,
            linkNames: true,
            blocks: [['type' => 'section']],
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('hi', $decoded['text']);
        $this->assertSame('bot', $decoded['username']);
        $this->assertSame(':smile:', $decoded['icon_emoji']);
        $this->assertSame('ts-1', $decoded['thread_ts']);
        $this->assertTrue($decoded['mrkdwn']);
        $this->assertTrue($decoded['link_names']);
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

    public function testNoAuthorizationHeader(): void
    {
        $this->mockClient->addResponse((new Response(200))->withBody($this->factory->createStream('ok')));
        $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
        $this->assertSame('', $this->mockClient->getRequests()[0]->getHeaderLine('Authorization'));
    }

    private function buildConnector(): SlackChatConnector
    {
        return new SlackChatConnector(
            new SlackConfig(webhookUrl: 'https://hooks.slack.com/services/T/B/C'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
