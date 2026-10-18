<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Apns;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\Contract\TokenCacheHook;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Apns\ApnsConfig;
use Thinwrap\Notifications\Providers\Apns\ApnsPushConnector;
use Thinwrap\Notifications\Providers\Apns\ApnsPushSendInput;

final class ApnsPushConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    private function cachedTokenHook(): TokenCacheHook
    {
        return new class implements TokenCacheHook {
            /** @var array<string, array{token:string,expiresAt:int}> */
            private array $store = [];
            public function __construct()
            {
                $this->store['seed'] = ['token' => 'apns-jwt', 'expiresAt' => time() + 1800];
            }
            public function get(string $cacheKey): ?array
            {
                return $this->store['seed'] ?? $this->store[$cacheKey] ?? null;
            }
            public function set(string $cacheKey, array $entry): void
            {
                $this->store[$cacheKey] = $entry;
            }
        };
    }

    public function testHappyPathReturnsApnsIdFromHeader(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'apns-msg-id']));

        $connector = new ApnsPushConnector(
            new ApnsConfig(
                keyId: 'K',
                teamId: 'T',
                bundleId: 'com.example',
                privateKey: 'mock',
                production: true,
                tokenCache: $this->cachedTokenHook(),
            ),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $result = $connector->send(new PushSendInput(to: 'tok', title: 'T', body: 'B'));
        $this->assertTrue($result->success);
        $this->assertSame(PushStatus::Sent, $result->status);
        $this->assertSame('apns-msg-id', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('bearer apns-jwt', $request->getHeaderLine('authorization'));
        $this->assertSame('com.example', $request->getHeaderLine('apns-topic'));
        $this->assertSame('alert', $request->getHeaderLine('apns-push-type'));
        // APNs is HTTP/2-only: the request must be pinned to protocol 2.0 so a
        // curl-backed PSR-18 client negotiates h2 (Guzzle otherwise forces 1.1).
        $this->assertSame('2.0', $request->getProtocolVersion());
    }

    public function testBadDeviceTokenMapsInvalidRecipient(): void
    {
        $payload = ['reason' => 'BadDeviceToken'];
        $this->mockClient->addResponse(
            (new Response(400, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $connector = new ApnsPushConnector(
            new ApnsConfig(keyId: 'K', teamId: 'T', bundleId: 'com.example', privateKey: 'mock', tokenCache: $this->cachedTokenHook()),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        try {
            $connector->send(new PushSendInput(to: 'tok', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    public function testNarrowedFieldsAndApnsTopicOverride(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $input = new ApnsPushSendInput(
            to: 'tok',
            title: 'T',
            body: 'B',
            badge: 5,
            sound: 'default',
            apnsTopic: 'com.override',
            apnsPriority: 10,
            apnsCollapseId: 'cid',
            ttl: 60,
        );
        (new ApnsPushConnector(
            new ApnsConfig(keyId: 'K', teamId: 'T', bundleId: 'com.example', privateKey: 'mock', tokenCache: $this->cachedTokenHook()),
            $this->mockClient,
            $this->factory,
            $this->factory,
        ))->send($input);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('com.override', $request->getHeaderLine('apns-topic'));
        $this->assertSame('10', $request->getHeaderLine('apns-priority'));
        $this->assertSame('cid', $request->getHeaderLine('apns-collapse-id'));

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(5, $decoded['aps']['badge']);
        $this->assertSame('default', $decoded['aps']['sound']);
    }

    private function connector(): ApnsPushConnector
    {
        return new ApnsPushConnector(
            new ApnsConfig(keyId: 'K', teamId: 'T', bundleId: 'com.example', privateKey: 'mock', tokenCache: $this->cachedTokenHook()),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    /**
     * Apple, sending-notification-requests: for `background`, "Always use
     * priority `5`. Using priority `10` is an error."; "If you omit this
     * header, APNs sets the notification priority to `10`." Pushing background
     * updates: "an `aps` dictionary that includes only the `content-available` key".
     */
    public function testDataOnlyPushIsABackgroundPushWithPriority5AndContentAvailable(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new PushSendInput(to: 'tok', data: ['order' => '42']));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('background', $request->getHeaderLine('apns-push-type'));
        $this->assertSame('5', $request->getHeaderLine('apns-priority'));
        $this->assertStringContainsString('"aps":{"content-available":1}', (string) $request->getBody());
        $this->assertStringContainsString('"order":"42"', (string) $request->getBody());
    }

    public function testCallerPriorityAndContentAvailableWinOnABackgroundPush(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new ApnsPushSendInput(to: 'tok', apnsPriority: 1, aps: ['content-available' => 0]));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('1', $request->getHeaderLine('apns-priority'));
        $this->assertStringContainsString('"aps":{"content-available":0}', (string) $request->getBody());
    }

    /** An explicit alert push with nothing in `aps` must still send `"aps":{}`, never `[]`. */
    public function testEmptyApsEncodesAsAnObject(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new ApnsPushSendInput(to: 'tok', apnsPushType: 'alert'));

        $this->assertStringContainsString('"aps":{}', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    public function testAlertFromApsOverrideIsSentAsAnAlertPush(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new ApnsPushSendInput(to: 'tok', aps: ['alert' => ['title' => 'Hi']]));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('alert', $request->getHeaderLine('apns-push-type'));
        $this->assertSame('', $request->getHeaderLine('apns-priority'));
        $this->assertStringNotContainsString('content-available', (string) $request->getBody());
    }

    public function testAlertFromPassthroughIsSentAsAnAlertPush(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new PushSendInput(to: 'tok', _passthrough: ['body' => ['aps' => ['alert' => 'Hello']]]));

        $this->assertSame('alert', $this->mockClient->getRequests()[0]->getHeaderLine('apns-push-type'));
    }

    /**
     * handling-notification-responses: 429 TooManyProviderTokenUpdates "The
     * provider's authentication token is being updated too often. Update the
     * authentication token no more than once every 20 minutes."
     */
    public function testTooManyProviderTokenUpdatesIsAuthFailedNamingTokenCache(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{"reason":"TooManyProviderTokenUpdates"}'))
        );

        try {
            $this->connector()->send(new PushSendInput(to: 'tok', title: 'T'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::AuthFailed, $err->providerCode);
            $this->assertStringContainsString('tokenCache', (string) $err->providerMessage);
        }
    }

    public function testOther429StaysRateLimited(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream('{"reason":"TooManyRequests"}'))
        );

        try {
            $this->connector()->send(new PushSendInput(to: 'tok', title: 'T'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
        }
    }

    /** Baseline: every 5xx is provider_unavailable, not only APNs' documented 500/503. */
    public function testAny5xxIsProviderUnavailable(): void
    {
        $this->mockClient->addResponse(new Response(502));

        try {
            $this->connector()->send(new PushSendInput(to: 'tok', title: 'T'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::ProviderUnavailable, $err->providerCode);
        }
    }

    /** Apple, "Generating a remote notification": `alert` is "Dictionary (or String)". */
    public function testTypedStringAlertIsSentVerbatimAsAnAlertPush(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new ApnsPushSendInput(to: 'tok', aps: ['alert' => 'Hello']));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('alert', $request->getHeaderLine('apns-push-type'));
        $this->assertSame('', $request->getHeaderLine('apns-priority'));
        $this->assertSame('{"aps":{"alert":"Hello"}}', (string) $request->getBody());
    }

    public function testTypedStringAlertWithATitleBecomesTheAlertBody(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new ApnsPushSendInput(to: 'tok', title: 'T', aps: ['alert' => 'Hello']));

        $this->assertSame('{"aps":{"alert":{"body":"Hello","title":"T"}}}', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    public function testObjectAlertKeepsItsKeysWhenATitleIsAdded(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new ApnsPushSendInput(to: 'tok', title: 'T', aps: ['alert' => (object) ['subtitle' => 'S']]));

        $this->assertSame('{"aps":{"alert":{"subtitle":"S","title":"T"}}}', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    public function testAnEmptyAlertIsNeverSent(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new ApnsPushSendInput(to: 'tok', aps: ['alert' => []], data: ['k' => 'v']));

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringNotContainsString('"alert"', $body);
        $this->assertSame('background', $this->mockClient->getRequests()[0]->getHeaderLine('apns-push-type'));
    }

    /**
     * sending-notification-requests-to-apns: `alert` is "The push type for
     * notifications that trigger a user interaction—for example, an alert,
     * badge, or sound."
     *
     * @return iterable<string,array{ApnsPushSendInput}>
     */
    public static function badgeOrSoundOnly(): iterable
    {
        yield 'badge 0 clears the badge' => [new ApnsPushSendInput(to: 'tok', badge: 0)];
        yield 'sound only' => [new ApnsPushSendInput(to: 'tok', sound: 'default')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badgeOrSoundOnly')]
    public function testBadgeOrSoundOnlyIsAnAlertPush(ApnsPushSendInput $input): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('alert', $request->getHeaderLine('apns-push-type'));
        $this->assertSame('', $request->getHeaderLine('apns-priority'));
        $this->assertStringNotContainsString('content-available', (string) $request->getBody());
    }

    /** A caller's `apns-push-type` header wins, and Apple's background rules follow it. */
    public function testPassthroughBackgroundHeaderGetsPriority5AndContentAvailable(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new PushSendInput(to: 'tok', title: 'T', _passthrough: ['headers' => ['Apns-Push-Type' => 'background']]));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('background', $request->getHeaderLine('apns-push-type'));
        $this->assertSame('5', $request->getHeaderLine('apns-priority'));
        $this->assertStringContainsString('"content-available":1', (string) $request->getBody());
    }

    public function testPassthroughAlertHeaderOnADataOnlyPushGetsNoBackgroundShaping(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new PushSendInput(to: 'tok', data: ['k' => 'v'], _passthrough: ['headers' => ['apns-push-type' => 'alert']]));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('alert', $request->getHeaderLine('apns-push-type'));
        $this->assertSame('', $request->getHeaderLine('apns-priority'));
        $this->assertStringNotContainsString('content-available', (string) $request->getBody());
    }

    public function testPassthroughApsObjectIsInspectedNotReplaced(): void
    {
        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'x']));

        $this->connector()->send(new PushSendInput(to: 'tok', _passthrough: ['body' => ['aps' => (object) ['alert' => 'Hi']]]));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('alert', $request->getHeaderLine('apns-push-type'));
        $this->assertSame('{"aps":{"alert":"Hi"}}', (string) $request->getBody());
    }

    public function testDerToP1363ConversionSucceedsWithRealKey(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            $this->markTestSkipped('openssl_pkey_new failed in environment');
        }
        $pem = '';
        openssl_pkey_export($key, $pem);

        $this->mockClient->addResponse(new Response(200, ['apns-id' => 'real']));

        $connector = new ApnsPushConnector(
            new ApnsConfig(keyId: 'K', teamId: 'T', bundleId: 'com.example', privateKey: $pem),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $result = $connector->send(new PushSendInput(to: 'tok', title: 'T', body: 'B'));
        $this->assertSame('real', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $auth = $request->getHeaderLine('authorization');
        $this->assertStringStartsWith('bearer ', $auth);
        $parts = explode('.', substr($auth, 7));
        $this->assertCount(3, $parts);
        $sigBytes = strlen(base64_decode(strtr($parts[2], '-_', '+/'), true) ?: '');
        $this->assertSame(64, $sigBytes, 'ES256 signature in P1363 form must be exactly 64 bytes (32 r + 32 s)');
    }
}
