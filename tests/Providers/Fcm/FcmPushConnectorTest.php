<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Fcm;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\Contract\TokenCacheHook;
use Thinwrap\Notifications\DTO\Push\PushSendInput;
use Thinwrap\Notifications\DTO\Push\PushStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Fcm\FcmConfig;
use Thinwrap\Notifications\Providers\Fcm\FcmPushConnector;

final class FcmPushConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    /** Generated EC private key (P-256) is NOT compatible with FCM RS256; tests use cached token to bypass minting. */
    private const SERVICE_ACCOUNT = [
        'project_id'   => 'proj-x',
        'client_email' => 'sa@proj-x.iam.gserviceaccount.com',
        'private_key'  => "-----BEGIN PRIVATE KEY-----\nMOCK\n-----END PRIVATE KEY-----\n",
    ];

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathWithCachedToken(): void
    {
        $cache = new class implements TokenCacheHook {
            /** @var array<string,array{token:string,expiresAt:int}> */
            private array $store = [];
            public function __construct()
            {
                $this->store['seed'] = ['token' => 'cached-token', 'expiresAt' => time() + 3600];
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

        $payload = ['name' => 'projects/proj-x/messages/abc'];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $connector = new FcmPushConnector(
            new FcmConfig(serviceAccount: self::SERVICE_ACCOUNT, tokenCache: $cache),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $result = $connector->send(new PushSendInput(to: 'devicetok', title: 'T', body: 'B'));

        $this->assertTrue($result->success);
        $this->assertSame(PushStatus::Sent, $result->status);
        $this->assertSame('projects/proj-x/messages/abc', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Bearer cached-token', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('/v1/projects/proj-x/messages:send', (string) $request->getUri());
    }

    public function testErrorMappingNotFoundAsInvalidRecipient(): void
    {
        $cache = new class implements TokenCacheHook {
            /** @var array<string,array{token:string,expiresAt:int}> */
            private array $store = [];
            public function __construct()
            {
                $this->store['seed'] = ['token' => 'c', 'expiresAt' => time() + 600];
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

        $payload = ['error' => ['status' => 'NOT_FOUND', 'message' => 'token not found']];
        $this->mockClient->addResponse(
            (new Response(404, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $connector = new FcmPushConnector(
            new FcmConfig(serviceAccount: self::SERVICE_ACCOUNT, tokenCache: $cache),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        try {
            $connector->send(new PushSendInput(to: 't', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    public function testInvalidPrivateKeyThrowsAuthFailedWhenNoCache(): void
    {
        $connector = new FcmPushConnector(
            new FcmConfig(serviceAccount: self::SERVICE_ACCOUNT),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        try {
            $connector->send(new PushSendInput(to: 't', title: 'T', body: 'B'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::AuthFailed, $err->providerCode);
        }
    }

    public function testConfigMissingServiceAccountKeyThrowsInvalidRequest(): void
    {
        // A malformed service-account array (here: `project_id` omitted) must
        // raise a clean typed InvalidRequest at construction — not a PHP
        // undefined-key warning followed by a TypeError on property assignment.
        try {
            // @phpstan-ignore-next-line — intentionally malformed for the guard test.
            new FcmConfig(serviceAccount: [
                'client_email' => 'sa@proj-x.iam.gserviceaccount.com',
                'private_key'  => "-----BEGIN PRIVATE KEY-----\nMOCK\n-----END PRIVATE KEY-----\n",
            ]);
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            $this->assertStringContainsString('project_id', (string) $err->providerMessage);
        }
    }

    private function cachedConnector(): FcmPushConnector
    {
        $cache = new class implements TokenCacheHook {
            public function get(string $cacheKey): ?array
            {
                return ['token' => 'cached-token', 'expiresAt' => time() + 3600];
            }

            public function set(string $cacheKey, array $entry): void {}
        };

        return new FcmPushConnector(
            new FcmConfig(serviceAccount: self::SERVICE_ACCOUNT, tokenCache: $cache),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    /**
     * FCM v1 Message: "Target to send a message to. The following is a list of
     * mutually exclusive fields. At most one of the fields will be set":
     * token, fid, topic, condition.
     */
    public function testPassthroughTopicReplacesTheDeviceToken(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '{"name":"n"}'));

        $this->cachedConnector()->send(new PushSendInput(
            to: 'devicetok',
            title: 'T',
            _passthrough: ['body' => ['message' => ['topic' => 'news']]],
        ));

        /** @var array{message:array<string,mixed>} $body */
        $body = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('news', $body['message']['topic']);
        $this->assertArrayNotHasKey('token', $body['message']);
    }

    public function testEmptyToSendsNoToken(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '{"name":"n"}'));

        $this->cachedConnector()->send(new PushSendInput(
            to: '',
            title: 'T',
            _passthrough: ['body' => ['message' => ['condition' => "'a' in topics"]]],
        ));

        $this->assertStringNotContainsString('"token"', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    /** FCM Message: exactly one of token, topic, condition, fid. */
    public function testMessageWithoutATargetIsRejectedBeforeSending(): void
    {
        try {
            $this->cachedConnector()->send(new PushSendInput(to: '', title: 'T'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $e) {
            $this->assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    public function testDeviceTokenStaysWhenNoOtherTargetIsGiven(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '{"name":"n"}'));

        $this->cachedConnector()->send(new PushSendInput(to: 'devicetok', title: 'T'));

        $this->assertStringContainsString('"token":"devicetok"', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    public function testAny5xxIsProviderUnavailable(): void
    {
        $this->mockClient->addResponse(new Response(502, [], '<html>Bad Gateway</html>'));

        try {
            $this->cachedConnector()->send(new PushSendInput(to: 'devicetok', title: 'T'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $e) {
            $this->assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
        }
    }

    /**
     * Google OAuth token endpoint: 400/401 (`invalid_grant`, `invalid_client`)
     * reject the credential; 429 and 5xx are throttling / outage.
     *
     * @return iterable<string,array{int,string,ProviderCode}>
     */
    public static function oauthFailures(): iterable
    {
        yield '400 invalid_grant' => [400, '{"error":"invalid_grant"}', ProviderCode::AuthFailed];
        yield '401 invalid_client' => [401, '{"error":"invalid_client"}', ProviderCode::AuthFailed];
        yield '429 throttled' => [429, '{"error":"rate_limit_exceeded"}', ProviderCode::RateLimited];
        yield '503 outage' => [503, '', ProviderCode::ProviderUnavailable];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('oauthFailures')]
    public function testOauthExchangeFailureMapping(int $status, string $body, ProviderCode $expected): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $pem = '';
        openssl_pkey_export($key, $pem);
        $this->mockClient->addResponse(new Response($status, ['Content-Type' => 'application/json'], $body));

        $connector = new FcmPushConnector(
            new FcmConfig(serviceAccount: ['project_id' => 'proj-x', 'client_email' => 'sa@proj-x.iam.gserviceaccount.com', 'private_key' => $pem]),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        try {
            $connector->send(new PushSendInput(to: 'devicetok', title: 'T'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $e) {
            $this->assertSame($expected, $e->providerCode);
        }
        $this->assertCount(1, $this->mockClient->getRequests());
        $this->assertSame('https://oauth2.googleapis.com/token', (string) $this->mockClient->getRequests()[0]->getUri());
    }
}
