<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\PusherBeams;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\PusherBeams\PusherBeamsConfig;
use Thinwrap\Notifications\Providers\PusherBeams\PusherBeamsPushConnector;
use Thinwrap\Notifications\Providers\PusherBeams\PusherBeamsPushSendInput;

final class PusherBeamsPushConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathUsersEndpoint(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{"publishId":"pub-1"}'))
        );

        $result = $this->buildConnector()->send(new PushSendInput(to: 'user-1', title: 'T', body: 'B'));
        $this->assertTrue($result->success);
        $this->assertSame(PushStatus::Sent, $result->status);
        $this->assertSame('pub-1', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertStringContainsString('/publishes/users', (string) $request->getUri());
        $this->assertSame('Bearer sec', $request->getHeaderLine('Authorization'));

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['user-1'], $decoded['users']);
        $this->assertArrayHasKey('fcm', $decoded);
        $this->assertArrayHasKey('apns', $decoded);
        $this->assertArrayHasKey('web', $decoded);
    }

    public function testInterestsRouting(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"publishId":"x"}'))
        );

        $input = new PusherBeamsPushSendInput(to: '', title: 'T', body: 'B', interests: ['news', 'sports']);
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        $this->assertStringContainsString('/publishes/interests', (string) $request->getUri());
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['news', 'sports'], $decoded['interests']);
    }

    public function testStringifyValuesInFcmData(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"publishId":"x"}'))
        );

        $this->buildConnector()->send(new PushSendInput(
            to: 'u',
            title: 'T',
            body: 'B',
            data: ['n' => 42, 's' => 'str'],
        ));

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('42', $decoded['fcm']['data']['n']);
        $this->assertSame('str', $decoded['fcm']['data']['s']);
        // Web preserves original types.
        $this->assertSame(42, $decoded['web']['data']['n']);
    }

    public function testInvalidRecipient422(): void
    {
        $this->mockClient->addResponse(new Response(422, ['Content-Type' => 'application/json']));
        try {
            $this->buildConnector()->send(new PushSendInput(to: 'u', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    private function buildConnector(): PusherBeamsPushConnector
    {
        return new PusherBeamsPushConnector(
            new PusherBeamsConfig(instanceId: 'inst-1', secretKey: 'sec'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
