<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Expo;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Expo\ExpoConfig;
use Thinwrap\Notifications\Providers\Expo\ExpoPushConnector;
use Thinwrap\Notifications\Providers\Expo\ExpoPushSendInput;

final class ExpoPushConnectorTest extends TestCase
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
        $payload = ['data' => ['status' => 'ok', 'id' => 'tkt-1']];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $result = $this->buildConnector()->send(new PushSendInput(to: 'ExponentPushToken[x]', title: 'T', body: 'B'));

        $this->assertTrue($result->success);
        $this->assertSame(PushStatus::Sent, $result->status);
        $this->assertSame('tkt-1', $result->providerMessageId);
    }

    public function testTicketErrorMaps(): void
    {
        $payload = ['data' => ['status' => 'error', 'message' => 'unreg', 'details' => ['error' => 'DeviceNotRegistered']]];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new PushSendInput(to: 't', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    public function testNarrowedFields(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"data":{"status":"ok","id":"x"}}'))
        );

        $input = new ExpoPushSendInput(
            to: 't',
            title: 'T',
            body: 'B',
            sound: 'default',
            badge: 3,
            ttl: 3600,
            priority: 'high',
            channelId: 'alerts',
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('default', $decoded['sound']);
        $this->assertSame(3, $decoded['badge']);
        $this->assertSame(3600, $decoded['ttl']);
        $this->assertSame('high', $decoded['priority']);
        $this->assertSame('alerts', $decoded['channelId']);
    }

    public function testAccessTokenSentAsBearer(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"data":{"status":"ok","id":"x"}}'))
        );
        $connector = new ExpoPushConnector(
            new ExpoConfig(accessToken: 'tk'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send(new PushSendInput(to: 't', title: 'T', body: 'B'));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Bearer tk', $request->getHeaderLine('Authorization'));
    }

    public function testRateLimitedWithRetryAfter(): void
    {
        $this->mockClient->addResponse(new Response(429, ['Retry-After' => '12']));
        try {
            $this->buildConnector()->send(new PushSendInput(to: 't', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 12 seconds', (string) $err->providerMessage);
        }
    }

    private function buildConnector(): ExpoPushConnector
    {
        return new ExpoPushConnector(
            new ExpoConfig(),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
