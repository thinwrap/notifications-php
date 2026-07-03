<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Mattermost;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Mattermost\MattermostChatConnector;
use Thinwrap\Notifications\Providers\Mattermost\MattermostChatSendInput;
use Thinwrap\Notifications\Providers\Mattermost\MattermostConfig;
use Thinwrap\Notifications\Providers\Mattermost\SlackCompatAttachment;

final class MattermostChatConnectorTest extends TestCase
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
            (new Response(200, ['Content-Type' => 'text/plain']))
                ->withBody($this->factory->createStream('ok'))
        );

        $result = $this->buildConnector()->send(new ChatSendInput(to: null, body: 'hello'));

        $this->assertTrue($result->success);
        $this->assertSame(ChatStatus::Sent, $result->status);
        $this->assertNull($result->providerMessageId);
        $this->assertSame('ok', $result->raw);
    }

    public function testAllNarrowedTopLevelFieldsWireMapped(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('ok'))
        );

        $input = new MattermostChatSendInput(
            body: 'hi',
            username: 'bot',
            iconUrl: 'https://x/icon.png',
            iconEmoji: ':robot_face:',
            channel: '#alerts',
            props: ['plugin' => 'x'],
            type: 'system_join_channel',
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('hi', $decoded['text']);
        $this->assertSame('bot', $decoded['username']);
        $this->assertSame('https://x/icon.png', $decoded['icon_url']);
        $this->assertSame(':robot_face:', $decoded['icon_emoji']);
        $this->assertSame('#alerts', $decoded['channel']);
        $this->assertSame(['plugin' => 'x'], $decoded['props']);
        $this->assertSame('system_join_channel', $decoded['type']);
    }

    public function testAttachmentFieldNameMapping(): void
    {
        $this->mockClient->addResponse((new Response(200))->withBody($this->factory->createStream('ok')));

        $att = new SlackCompatAttachment(
            color: 'good',
            authorName: 'Alice',
            authorLink: 'https://a',
            authorIcon: 'https://a.png',
            title: 'T',
            titleLink: 'https://t',
            imageUrl: 'https://i',
            thumbUrl: 'https://th',
            footer: 'F',
            footerIcon: 'https://f',
        );
        $input = new MattermostChatSendInput(body: 'x', attachments: [$att]);
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $wire = $decoded['attachments'][0];
        $this->assertSame('Alice', $wire['author_name']);
        $this->assertSame('https://a', $wire['author_link']);
        $this->assertSame('https://a.png', $wire['author_icon']);
        $this->assertSame('https://t', $wire['title_link']);
        $this->assertSame('https://i', $wire['image_url']);
        $this->assertSame('https://th', $wire['thumb_url']);
        $this->assertSame('https://f', $wire['footer_icon']);
        $this->assertArrayNotHasKey('authorName', $wire);
        $this->assertArrayNotHasKey('imageUrl', $wire);
    }

    public function test404MapsToAuthFailed(): void
    {
        $this->mockClient->addResponse(new Response(404));

        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::AuthFailed, $err->providerCode);
        }
    }

    public function testRateLimitedWithRetryAfter(): void
    {
        $this->mockClient->addResponse(new Response(429, ['Retry-After' => '15']));

        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 15 seconds', (string) $err->providerMessage);
            /** @var array<string,mixed> $cause */
            $cause = $err->cause;
            $this->assertSame('15', $cause['retryAfter'] ?? null);
        }
    }

    public function testPassthroughHonored(): void
    {
        $this->mockClient->addResponse((new Response(200))->withBody($this->factory->createStream('ok')));

        $input = new MattermostChatSendInput(
            body: 'x',
            _passthrough: [
                'body' => ['attachments' => [['color' => 'good']]],
                'headers' => ['X-Idempotency-Key' => 'k'],
            ],
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('good', $decoded['attachments'][0]['color']);
        $this->assertSame('k', $request->getHeaderLine('X-Idempotency-Key'));
    }

    private function buildConnector(): MattermostChatConnector
    {
        return new MattermostChatConnector(
            new MattermostConfig(webhookUrl: 'https://mm.example.com/hooks/abc'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
