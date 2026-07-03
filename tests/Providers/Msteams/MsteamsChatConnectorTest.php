<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Msteams;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Msteams\MsteamsChatConnector;
use Thinwrap\Notifications\Providers\Msteams\MsteamsChatSendInput;
use Thinwrap\Notifications\Providers\Msteams\MsteamsConfig;

final class MsteamsChatConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathSynthesizesAdaptiveCard(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'text/plain']))
                ->withBody($this->factory->createStream('1'))
        );

        $result = $this->buildConnector()->send(new ChatSendInput(to: null, body: 'hello'));
        $this->assertTrue($result->success);
        $this->assertSame(ChatStatus::Sent, $result->status);
        $this->assertNull($result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('message', $decoded['type']);
        $this->assertSame('application/vnd.microsoft.card.adaptive', $decoded['attachments'][0]['contentType']);
        $this->assertSame('AdaptiveCard', $decoded['attachments'][0]['content']['type']);
        $this->assertSame('hello', $decoded['attachments'][0]['content']['body'][0]['text']);
    }

    public function testProvidedCardWraps(): void
    {
        $this->mockClient->addResponse((new Response(200))->withBody($this->factory->createStream('1')));

        $card = ['type' => 'AdaptiveCard', 'version' => '1.5', 'body' => [['type' => 'TextBlock', 'text' => 'X']]];
        $this->buildConnector()->send(new MsteamsChatSendInput(body: 'ignored', card: $card));

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($card, $decoded['attachments'][0]['content']);
    }

    public function testInvalidWebhookErrorTextMapsAuthFailed(): void
    {
        $this->mockClient->addResponse(
            (new Response(400, ['Content-Type' => 'text/plain']))
                ->withBody($this->factory->createStream('Invalid webhook URL'))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::AuthFailed, $err->providerCode);
        }
    }

    private function buildConnector(): MsteamsChatConnector
    {
        return new MsteamsChatConnector(
            new MsteamsConfig(webhookUrl: 'https://outlook.office.com/webhook/abc/IncomingWebhook/def/ghi'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
