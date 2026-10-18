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

    /**
     * Beams publish API: 401 "Incorrect API Key", 402 "over plan limits",
     * 404 "Could not find the instance", 422 "JSON does not match our schema",
     * 429 "Too many requests", 500 "Internal server error".
     *
     * @return iterable<string,array{int,ProviderCode}>
     */
    public static function publishApiStatuses(): iterable
    {
        yield '400 bad request' => [400, ProviderCode::InvalidRequest];
        yield '401 incorrect API key' => [401, ProviderCode::AuthFailed];
        yield '402 over plan limits' => [402, ProviderCode::RateLimited];
        yield '404 instance not found' => [404, ProviderCode::InvalidRequest];
        yield '422 schema error' => [422, ProviderCode::InvalidRequest];
        yield '429 too many requests' => [429, ProviderCode::RateLimited];
        yield '500 internal error' => [500, ProviderCode::ProviderUnavailable];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publishApiStatuses')]
    public function testPublishApiStatusMapping(int $status, ProviderCode $expected): void
    {
        $this->mockClient->addResponse(new Response($status, ['Content-Type' => 'application/json'], '{"error":"Unprocessable","description":"interests must be non-empty"}'));
        try {
            $this->buildConnector()->send(new PushSendInput(to: 'u', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame($expected, $err->providerCode);
            $this->assertSame('Unprocessable: interests must be non-empty', $err->providerMessage);
        }
    }

    /**
     * Beams publish payloads: `apns` is Apple's payload with custom data under
     * `apns.data` (`apns: { aps: { alert: {…} }, data: {…} }`); APNs `alert`
     * must be a string or dictionary and FCM `notification` an object, so a
     * data-only push sends neither and marks iOS `content-available`.
     */
    public function testDataOnlyPushSendsObjectsAndIosData(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '{"publishId":"p"}'));

        $this->buildConnector()->send(new PushSendInput(to: 'user-1', data: ['order' => '42']));

        $raw = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringNotContainsString('[]', $raw);
        /** @var array<string,mixed> $body */
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['content-available' => 1], $body['apns']['aps']);
        $this->assertSame(['order' => '42'], $body['apns']['data']);
        $this->assertArrayNotHasKey('notification', $body['fcm']);
        $this->assertSame(['order' => '42'], $body['fcm']['data']);
        $this->assertArrayNotHasKey('notification', $body['web']);
    }

    public function testAlertPushKeepsTheAlertAndAddsIosData(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '{"publishId":"p"}'));

        $this->buildConnector()->send(new PushSendInput(to: 'user-1', title: 'Hi', data: ['k' => 'v']));

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['title' => 'Hi'], $body['apns']['aps']['alert']);
        $this->assertArrayNotHasKey('content-available', $body['apns']['aps']);
        $this->assertSame(['k' => 'v'], $body['apns']['data']);
        $this->assertSame(['title' => 'Hi'], $body['fcm']['notification']);
    }

    /** The instance id becomes `https://{instanceId}.pushnotifications.pusher.com/…` with the secret key attached. */
    public function testInstanceIdThatIsNotAHostLabelIsRejectedBeforeSending(): void
    {
        // A trailing newline must fail too: the whole string is matched.
        foreach (['evil.example/x', "abc\n"] as $instanceId) {
            $connector = new PusherBeamsPushConnector(
                new PusherBeamsConfig(instanceId: $instanceId, secretKey: 'sk'),
                $this->mockClient,
                $this->factory,
                $this->factory,
            );

            try {
                $connector->send(new PushSendInput(to: 'user-1', title: 'T'));
                $this->fail('Expected ConnectorError');
            } catch (ConnectorError $e) {
                $this->assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            }
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    public function testAny5xxIsProviderUnavailable(): void
    {
        $this->mockClient->addResponse(new Response(502));

        try {
            $this->buildConnector()->send(new PushSendInput(to: 'user-1', title: 'T'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $e) {
            $this->assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
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
