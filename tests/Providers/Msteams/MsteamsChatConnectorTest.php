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
    private const WORKFLOWS_URL = 'https://prod-00.westus.logic.azure.com:443/workflows/abc/triggers/manual/paths/invoke?api-version=2016-06-01&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=secret';

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

    public function testWorkflows202EmptyBodyIsSuccess(): void
    {
        // Logic Apps Request trigger without a Response action: 202 ACCEPTED, no body.
        $this->mockClient->addResponse(new Response(202));

        $result = $this->buildConnector(self::WORKFLOWS_URL)->send(new ChatSendInput(to: null, body: 'x'));
        $this->assertTrue($result->success);
        $this->assertSame(ChatStatus::Sent, $result->status);
        $this->assertNull($result->providerMessageId);
    }

    public function testLegacyHost202EmptyBodyIsSuccess(): void
    {
        $this->mockClient->addResponse(new Response(202));

        $result = $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
        $this->assertTrue($result->success);
    }

    public function testWorkflows200EmptyBodyIsSuccess(): void
    {
        $this->mockClient->addResponse(new Response(200));

        $result = $this->buildConnector(self::WORKFLOWS_URL)->send(new ChatSendInput(to: null, body: 'x'));
        $this->assertTrue($result->success);
    }

    public function testPowerPlatform200EmptyBodyIsSuccess(): void
    {
        $this->mockClient->addResponse(new Response(200));

        $result = $this->buildConnector(
            'https://default0123.ab.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/abc/triggers/manual/paths/invoke?api-version=1&sig=secret',
        )->send(new ChatSendInput(to: null, body: 'x'));
        $this->assertTrue($result->success);
    }

    public function testLegacyTenantHost200OneIsSuccess(): void
    {
        $this->mockClient->addResponse((new Response(200))->withBody($this->factory->createStream("1\n")));

        $result = $this->buildConnector('https://contoso.webhook.office.com/webhookb2/abc/IncomingWebhook/def/ghi')
            ->send(new ChatSendInput(to: null, body: 'x'));
        $this->assertTrue($result->success);
    }

    public function testLegacyHost200OtherTextIsProviderUnavailable(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('Microsoft Teams endpoint returned an unexpected response'))
        );

        try {
            $this->buildConnector('https://contoso.webhook.office.com/webhookb2/abc/IncomingWebhook/def/ghi')
                ->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::ProviderUnavailable, $err->providerCode);
            $this->assertSame(502, $err->statusCode);
        }
    }

    public function testEmbeddedHttpErrorOn200MapsEmbeddedStatus(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream(
                'Webhook message delivery failed with error: Microsoft Teams endpoint returned HTTP error 413 with ContextId tcid=0,server=msgapi,cv=abc',
            ))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(413, $err->statusCode);
            $this->assertSame(ProviderCode::Unknown, $err->providerCode);
        }
    }

    public function testEmbeddedHttp429On200MapsRateLimited(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream(
                'Microsoft Teams endpoint returned HTTP error 429 with ContextId tcid=0',
            ))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertSame(429, $err->statusCode);
        }
    }

    private function buildConnector(string $webhookUrl = 'https://outlook.office.com/webhook/abc/IncomingWebhook/def/ghi'): MsteamsChatConnector
    {
        return new MsteamsChatConnector(
            new MsteamsConfig(webhookUrl: $webhookUrl),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
