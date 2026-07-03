<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\OneSignal;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\OneSignal\OneSignalConfig;
use Thinwrap\Notifications\Providers\OneSignal\OneSignalPushConnector;
use Thinwrap\Notifications\Providers\OneSignal\OneSignalPushSendInput;

final class OneSignalPushConnectorTest extends TestCase
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
        $payload = ['id' => 'notif-abc', 'recipients' => 1];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $result = $this->buildConnector()->send(new PushSendInput(to: 'sub-1', title: 'T', body: 'B'));
        $this->assertTrue($result->success);
        $this->assertSame(PushStatus::Sent, $result->status);
        $this->assertSame('notif-abc', $result->providerMessageId);
    }

    public function testBasicAuthAndAppIdInBody(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"id":"x"}'))
        );
        $this->buildConnector()->send(new PushSendInput(to: 'sub-1', title: 'T', body: 'B'));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Basic apikey-x', $request->getHeaderLine('Authorization'));
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('appid-1', $decoded['app_id']);
        $this->assertSame(['sub-1'], $decoded['include_subscription_ids']);
        $this->assertSame(['en' => 'T'], $decoded['headings']);
        $this->assertSame(['en' => 'B'], $decoded['contents']);
    }

    public function testEmptyRecipientSendsEmptyStringSubscriptionId(): void
    {
        // An empty `to` with no augmentation mirrors TS's
        // `to !== undefined` semantics — the recipient array is `['']`, not
        // omitted.
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"id":"x"}'))
        );
        $this->buildConnector()->send(new PushSendInput(to: '', title: 'T', body: 'B'));

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([''], $decoded['include_subscription_ids']);
    }

    public function testDataOnlyPushOmitsTitleAndBody(): void
    {
        // a documented design decision: title/body are nullable; a data-only push omits headings/contents.
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"id":"x"}'))
        );
        $this->buildConnector()->send(new PushSendInput(to: 'sub-1', data: ['k' => 'v']));

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('headings', $decoded);
        $this->assertArrayNotHasKey('contents', $decoded);
        $this->assertSame(['k' => 'v'], $decoded['data']);
    }

    public function testInvalidRecipientFromErrorsField(): void
    {
        $payload = ['id' => '', 'errors' => ['All included players are not subscribed']];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new PushSendInput(to: 'sub-x', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    public function testRateLimitedWithRetryAfter(): void
    {
        $this->mockClient->addResponse(new Response(429, ['Retry-After' => '7']));
        try {
            $this->buildConnector()->send(new PushSendInput(to: 's', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 7 seconds', (string) $err->providerMessage);
        }
    }

    public function testNarrowedAugmentationOverridesTo(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"id":"x"}'))
        );

        $input = new OneSignalPushSendInput(
            to: '',
            headings: ['en' => 'Hi', 'es' => 'Hola'],
            contents: ['en' => 'B'],
            include_external_user_ids: ['u1', 'u2'],
            ttl: 60,
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('include_subscription_ids', $decoded);
        $this->assertSame(['u1', 'u2'], $decoded['include_external_user_ids']);
        $this->assertSame(['en' => 'Hi', 'es' => 'Hola'], $decoded['headings']);
        $this->assertSame(60, $decoded['ttl']);
    }

    private function buildConnector(): OneSignalPushConnector
    {
        return new OneSignalPushConnector(
            new OneSignalConfig(appId: 'appid-1', restApiKey: 'apikey-x'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
