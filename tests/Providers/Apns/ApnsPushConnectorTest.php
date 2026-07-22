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
