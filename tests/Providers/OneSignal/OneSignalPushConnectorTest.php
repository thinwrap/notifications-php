<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\OneSignal;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame('https://onesignal.com/api/v1/notifications', (string) $request->getUri());
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

    /**
     * Create-notification reference: "Message Sent with Errors" carries the
     * notification `id` plus `errors.invalid_*`; only "Message Not Sent" has an
     * empty id. The valid recipients were notified, so this is a success.
     */
    public function testSentWithErrorsIsASuccessWithTheErrorsOnRaw(): void
    {
        $payload = ['id' => 'b98881cc-1e94-4366-bbd9-db8f3429292b', 'errors' => ['invalid_player_ids' => ['bad-id']]];
        $this->mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)));

        $result = $this->buildConnector()->send(new PushSendInput(to: 'sub-x', title: 'T', body: 'B'));

        $this->assertTrue($result->success);
        $this->assertSame('b98881cc-1e94-4366-bbd9-db8f3429292b', $result->providerMessageId);
        $this->assertSame($payload, $result->raw);
    }

    /** OneSignal: 403 means the key lacks permission for the app. */
    public function test403IsAuthFailed(): void
    {
        $this->mockClient->addResponse(new Response(403, ['Content-Type' => 'application/json'], '{"errors":["forbidden"]}'));
        try {
            $this->buildConnector()->send(new PushSendInput(to: 's', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::AuthFailed, $err->providerCode);
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

    public function testCurrentAppApiKeyUsesApiHostAndKeyScheme(): void
    {
        // documentation.onesignal.com/reference/push-notification:
        // POST https://api.onesignal.com/notifications?c=push, Authorization: Key <App API key>.
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"id":"b98881cc-1e94-4366-bbd9-db8f3429292b","external_id":null}'))
        );
        $result = $this->buildConnector('os_v2_app_abc123')->send(new PushSendInput(to: 'sub-1', title: 'T', body: 'B'));
        $this->assertSame('b98881cc-1e94-4366-bbd9-db8f3429292b', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.onesignal.com/notifications?c=push', (string) $request->getUri());
        $this->assertSame('Key os_v2_app_abc123', $request->getHeaderLine('Authorization'));
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('appid-1', $decoded['app_id']);
        $this->assertSame(['sub-1'], $decoded['include_subscription_ids']);
    }

    public function testPassthroughIncludeAliasesSuppressesDefaultTarget(): void
    {
        // include_aliases is "not compatible with any other targeting parameters",
        // so the connector must not add include_subscription_ids next to it.
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"id":"x"}'))
        );
        $this->buildConnector('os_v2_app_abc123')->send(new PushSendInput(
            to: 'ignored',
            title: 'T',
            body: 'B',
            _passthrough: ['body' => [
                'include_aliases' => ['external_id' => ['user-1']],
                'target_channel'  => 'push',
            ]],
        ));

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('include_subscription_ids', $decoded);
        $this->assertSame(['external_id' => ['user-1']], $decoded['include_aliases']);
        $this->assertSame('push', $decoded['target_channel']);
    }

    /**
     * @return iterable<string,array{string,mixed}>
     */
    public static function passthroughTargetingProvider(): iterable
    {
        yield 'included_segments' => ['included_segments', ['Total Subscriptions']];
        yield 'excluded_segments' => ['excluded_segments', ['Inactive']];
        yield 'filters' => ['filters', [['field' => 'tag', 'key' => 'level', 'relation' => '>', 'value' => '10']]];
        yield 'include_player_ids' => ['include_player_ids', ['p-1']];
        yield 'include_external_user_ids' => ['include_external_user_ids', ['u-1']];
        yield 'include_email_tokens' => ['include_email_tokens', ['a@example.com']];
        yield 'include_phone_numbers' => ['include_phone_numbers', ['+15555550100']];
    }

    #[DataProvider('passthroughTargetingProvider')]
    public function testAnyPassthroughTargetingSuppressesDefaultTarget(string $key, mixed $value): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"id":"x"}'))
        );
        $this->buildConnector()->send(new PushSendInput(
            to: 'ignored',
            title: 'T',
            _passthrough: ['body' => [$key => $value]],
        ));

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('include_subscription_ids', $decoded);
        $this->assertSame($value, $decoded[$key]);
    }

    private function buildConnector(string $restApiKey = 'apikey-x'): OneSignalPushConnector
    {
        return new OneSignalPushConnector(
            new OneSignalConfig(appId: 'appid-1', restApiKey: $restApiKey),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
