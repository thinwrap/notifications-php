<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Wonderpush;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Wonderpush\WonderpushConfig;
use Thinwrap\Notifications\Providers\Wonderpush\WonderpushPushConnector;
use Thinwrap\Notifications\Providers\Wonderpush\WonderpushPushSendInput;

final class WonderpushPushConnectorTest extends TestCase
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
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{"id":"wp-1"}'))
        );

        $result = $this->buildConnector()->send(new PushSendInput(to: 'user-1', title: 'T', body: 'B'));
        $this->assertTrue($result->success);
        $this->assertSame(PushStatus::Sent, $result->status);
        $this->assertSame('wp-1', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Bearer tk', $request->getHeaderLine('Authorization'));
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('app-x', $decoded['applicationId']);
        $this->assertSame(['user-1'], $decoded['targetUserIds']);
        $this->assertSame('T', $decoded['notification']['alert']['title']);
        $this->assertSame('B', $decoded['notification']['alert']['text']);
    }

    public function testNotFoundMapsInvalidRecipient(): void
    {
        $this->mockClient->addResponse(new Response(404, ['Content-Type' => 'application/json']));
        try {
            $this->buildConnector()->send(new PushSendInput(to: 'u', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    public function testNarrowedSegmentTargeting(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"id":"x"}'))
        );

        $input = new WonderpushPushSendInput(
            to: 'u',
            title: 'T',
            body: 'B',
            badge: 5,
            sound: 'default',
            targetUserIds: ['a', 'b'],
            targetSegmentIds: ['seg1'],
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['a', 'b'], $decoded['targetUserIds']);
        $this->assertSame(['seg1'], $decoded['targetSegmentIds']);
        $this->assertSame(5, $decoded['notification']['badge']);
    }

    private function buildConnector(): WonderpushPushConnector
    {
        return new WonderpushPushConnector(
            new WonderpushConfig(applicationId: 'app-x', accessToken: 'tk'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
