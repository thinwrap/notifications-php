<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Base;

use Http\Client\Exception\NetworkException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;

#[CoversClass(\Thinwrap\Notifications\Base\BaseConnector::class)]
final class BaseConnectorTest extends TestCase
{
    private function makeConnector(ClientInterface $client, array $authHeaders = []): TestableConnector
    {
        $factory = new Psr17Factory();

        return new TestableConnector($client, $factory, $factory, $authHeaders);
    }

    /**
     * PSR-18 requires a non-2xx to be RETURNED, not thrown. A client that raises
     * instead (Guzzle's BadResponseException shape) still answered, so the caller
     * must see the real status rather than an apparent outage.
     */
    public function testDispatchRecoversTheResponseFromAClientThatThrowsOnNon2xx(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('429 Too Many Requests', new Response(429, ['Retry-After' => '30'], '{"error":"slow down"}')) extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {
                    public function __construct(string $message, private readonly ResponseInterface $response)
                    {
                        parent::__construct($message);
                    }

                    public function getResponse(): ResponseInterface
                    {
                        return $this->response;
                    }
                };
            }
        };

        $response = $this->makeConnector($client)->callSendGet('https://example.test/api');

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('30', $response->getHeaderLine('Retry-After'));
        self::assertSame('{"error":"slow down"}', (string) $response->getBody());
    }

    /**
     * getResponse() returning null (Guzzle's ConnectException shape) is a genuine
     * transport failure and must not be mistaken for an answer.
     */
    public function testDispatchStillReportsATransportFailureWithNoResponse(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('connection refused') extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {
                    public function getResponse(): ?ResponseInterface
                    {
                        return null;
                    }
                };
            }
        };

        try {
            $this->makeConnector($client)->callSendGet('https://example.test/api');
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
        }
    }

    public function testInjectedClientIsUsed(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(200, [], '{}'));

        $connector = $this->makeConnector($client);
        $connector->callSendGet('https://example.test/api');

        self::assertCount(1, $client->getRequests());
    }

    public function testSendPostJsonContentType(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(200, [], '{}'));

        $connector = $this->makeConnector($client);
        $connector->callSendPostJson('https://example.test/api', ['k' => 'v']);

        $request = $client->getRequests()[0];
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testSendPostFormContentType(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(200, [], '{}'));

        $connector = $this->makeConnector($client);
        $connector->callSendPostForm('https://example.test/api', ['k' => 'v']);

        $request = $client->getRequests()[0];
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
    }

    public function testSendPostFormMultiValueRepeatedKeys(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(200, [], '{}'));

        $connector = $this->makeConnector($client);
        $connector->callSendPostForm(
            'https://example.test/api',
            ['MediaUrl' => ['https://a.png', 'https://b.png']],
        );

        $request = $client->getRequests()[0];
        $body = (string) $request->getBody();

        self::assertSame(2, substr_count($body, 'MediaUrl='));
        self::assertStringNotContainsString('MediaUrl%5B', $body);
    }

    public function testSendPostJsonRawByteExactPassthrough(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(200, [], '{}'));

        $connector = $this->makeConnector($client);
        $payload = '{"key":"value with /slash"}';
        $connector->callSendPostJsonRaw('https://example.test/api', $payload);

        $request = $client->getRequests()[0];
        self::assertSame($payload, (string) $request->getBody());
    }

    public function testSendPostMultipartBoundaryAndParts(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(200, [], '{}'));

        $connector = $this->makeConnector($client);
        $connector->callSendPostMultipart(
            'https://example.test/api',
            [
                ['name' => 'from', 'contents' => 'sender@example.com'],
                ['name' => 'subject', 'contents' => 'Hello'],
                [
                    'name'        => 'attachment',
                    'filename'    => 'invoice.pdf',
                    'contentType' => 'application/pdf',
                    'contents'    => 'PDF-BYTES',
                ],
            ],
        );

        $request = $client->getRequests()[0];
        $body = (string) $request->getBody();

        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        self::assertStringContainsString('name="from"', $body);
        self::assertStringContainsString('name="subject"', $body);
        self::assertStringContainsString('filename="invoice.pdf"', $body);
        self::assertStringContainsString('Content-Type: application/pdf', $body);
        self::assertStringContainsString('PDF-BYTES', $body);
    }

    public function testClientExceptionWrappedAsProviderUnavailable(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new NetworkException('DNS failure', $request);
            }
        };

        $connector = $this->makeConnector($client);

        try {
            $connector->callSendGet('https://example.test/api');
            self::fail('Expected ConnectorError to be thrown');
        } catch (ConnectorError $err) {
            self::assertSame(ProviderCode::ProviderUnavailable, $err->providerCode);
            /** @var array<string, mixed>|null $cause */
            $cause = $err->cause;
            self::assertIsArray($cause);
            // The unified cause shape: transport failures nest the exception
            // detail under `raw` alongside a null `retryAfter`.
            self::assertArrayHasKey('raw', $cause);
            self::assertArrayHasKey('retryAfter', $cause);
            self::assertNull($cause['retryAfter']);
            self::assertIsArray($cause['raw']);
            self::assertArrayHasKey('exception', $cause['raw']);
            self::assertStringContainsString('NetworkException', (string) $cause['raw']['exception']);
        }
    }

    public function testGetAuthHeadersMergedIntoRequest(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(200, [], '{}'));

        $connector = $this->makeConnector($client, ['Authorization' => 'Bearer test-token']);
        $connector->callSendGet('https://example.test/api');

        $request = $client->getRequests()[0];
        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    }

    public function testHeaderMergeOrderCallerWinsOnCollision(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(200, [], '{}'));

        $connector = $this->makeConnector($client, [
            'X-Auth'   => 'a',
            'X-Common' => 'auth',
        ]);
        $connector->callSendGet(
            'https://example.test/api',
            ['X-Caller' => 'c', 'X-Common' => 'caller'],
        );

        $request = $client->getRequests()[0];
        self::assertSame('a', $request->getHeaderLine('X-Auth'));
        self::assertSame('c', $request->getHeaderLine('X-Caller'));
        self::assertSame('caller', $request->getHeaderLine('X-Common'));
    }

    public function testNonNetworkResponseReturnedRawToCaller(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(500, [], '{"error":"boom"}'));

        $connector = $this->makeConnector($client);
        $response = $connector->callSendGet('https://example.test/api');

        self::assertSame(500, $response->getStatusCode());
    }
}
