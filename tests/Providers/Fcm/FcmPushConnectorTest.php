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
}
