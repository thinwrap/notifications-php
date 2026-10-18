<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Twilio;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Twilio\TwilioConfig;
use Thinwrap\Notifications\Providers\Twilio\TwilioNarrowedInput;
use Thinwrap\Notifications\Providers\Twilio\TwilioRegion;
use Thinwrap\Notifications\Providers\Twilio\TwilioSmsConnector;

final class TwilioSmsConnectorTest extends TestCase
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
            $this->jsonResponse(201, ['sid' => 'SM-1'])
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('SM-1', $result->providerMessageId);
    }

    public function testBasicAuthHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $expected = 'Basic ' . base64_encode('ACsid:tok');
        $this->assertSame($expected, $request->getHeaderLine('Authorization'));
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
    }

    public function testUs1DefaultHost(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://api.twilio.com/2010-04-01/Accounts/ACsid/Messages.json', (string) $request->getUri());
    }

    /**
     * Twilio legacy-regions page: "The `api.REGION.twilio.com` domain pattern
     * (e.g., `api.ie1.twilio.com`) will stop working on April 28, 2026"; the
     * current hosts are `api.dublin.ie1.twilio.com` / `api.sydney.au1.twilio.com`,
     * and "`api.us1.twilio.com` remains valid". Non-US format is
     * `PRODUCT.EDGE_LOCATION.PROCESSING_REGION.twilio.com`.
     *
     * @return iterable<string,array{TwilioRegion|null,\Thinwrap\Notifications\Providers\Twilio\TwilioEdge|null,string}>
     */
    public static function regionHosts(): iterable
    {
        yield 'default is us1 on api.twilio.com' => [null, null, 'api.twilio.com'];
        yield 'us1 without edge' => [TwilioRegion::Us1, null, 'api.twilio.com'];
        yield 'us1 with an edge' => [TwilioRegion::Us1, \Thinwrap\Notifications\Providers\Twilio\TwilioEdge::Frankfurt, 'api.frankfurt.us1.twilio.com'];
        yield 'ie1 defaults to dublin' => [TwilioRegion::Ie1, null, 'api.dublin.ie1.twilio.com'];
        yield 'au1 defaults to sydney' => [TwilioRegion::Au1, null, 'api.sydney.au1.twilio.com'];
        yield 'edge overrides the default' => [TwilioRegion::Ie1, \Thinwrap\Notifications\Providers\Twilio\TwilioEdge::Frankfurt, 'api.frankfurt.ie1.twilio.com'];
    }

    #[DataProvider('regionHosts')]
    public function testRegionalHost(?TwilioRegion $region, ?\Thinwrap\Notifications\Providers\Twilio\TwilioEdge $edge, string $host): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new TwilioSmsConnector(
            new TwilioConfig(accountSid: 'ACsid', authToken: 'tok', from: '+15550000000', region: $region, edge: $edge),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame("https://{$host}/2010-04-01/Accounts/ACsid/Messages.json", (string) $request->getUri());
    }

    /**
     * @return iterable<string,array{TwilioRegion}>
     */
    public static function edgeOnlyRegions(): iterable
    {
        // legacy-regions: br1/de1/jp1/sg1/us2 "process in US1 — use api.twilio.com".
        foreach ([TwilioRegion::Us2, TwilioRegion::Br1, TwilioRegion::De1, TwilioRegion::Jp1, TwilioRegion::Sg1] as $region) {
            yield $region->value => [$region];
        }
    }

    #[DataProvider('edgeOnlyRegions')]
    public function testEdgeOnlyRegionIsRejectedBeforeAnyCredentialedRequest(TwilioRegion $region): void
    {
        foreach ([null, \Thinwrap\Notifications\Providers\Twilio\TwilioEdge::Umatilla] as $edge) {
            $this->assertRejectedBeforeSending($region, $edge);
        }
    }

    private function assertRejectedBeforeSending(TwilioRegion $region, ?\Thinwrap\Notifications\Providers\Twilio\TwilioEdge $edge): void
    {
        $connector = new TwilioSmsConnector(
            new TwilioConfig(accountSid: 'ACsid', authToken: 'tok', from: '+15550000000', region: $region, edge: $edge),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $e) {
            $this->assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    public function testAccountSidIsPathEscaped(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        (new TwilioSmsConnector(
            new TwilioConfig(accountSid: 'AC1/../x?', authToken: 'tok', from: '+15550000000'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        ))->send($this->basicInput());

        $this->assertSame('https://api.twilio.com/2010-04-01/Accounts/AC1%2F..%2Fx%3F/Messages.json', (string) $this->mockClient->getRequests()[0]->getUri());
    }

    /** The edge list is closed: an unknown edge cannot be built, like an unknown region. */
    public function testUnknownEdgeIsANativeValueError(): void
    {
        $this->expectException(\ValueError::class);
        \Thinwrap\Notifications\Providers\Twilio\TwilioEdge::from('evil.example');
    }

    public function testMediaUrlMultiValueFormEncoding(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new TwilioNarrowedInput(
            to: '+15551234567',
            body: 'hi',
            from: '+15550000000',
            mediaUrl: ['https://x/a.png', 'https://x/b.png'],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        $body = (string) $request->getBody();
        // Both MediaUrl= entries present, never comma-joined.
        $this->assertSame(2, substr_count($body, 'MediaUrl=https'));
    }

    public function testNeitherFromNorMessagingServiceThrows(): void
    {
        $connector = new TwilioSmsConnector(
            new TwilioConfig(accountSid: 'ACsid', authToken: 'tok'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        try {
            $connector->send(new SmsSendInput(to: '+15551234567', body: 'hi'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['message' => 'auth'], [], ProviderCode::AuthFailed, null];
        yield '429 retry' => [429, ['message' => 'rate'], ['Retry-After' => '5'], ProviderCode::RateLimited, '5'];
        yield '500' => [500, ['message' => 'oops'], [], ProviderCode::ProviderUnavailable, null];
        yield '400 code 21211 recipient' => [400, ['message' => 'bad to', 'code' => 21211], [], ProviderCode::InvalidRecipient, null];
        yield '400 code 20003 auth' => [400, ['message' => 'auth', 'code' => 20003], [], ProviderCode::AuthFailed, null];
        yield '400 generic' => [400, ['message' => 'bad', 'code' => 12345], [], ProviderCode::InvalidRequest, null];
    }

    #[DataProvider('errorScenarios')]
    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    public function testErrorMapping(int $status, array $body, array $headers, ProviderCode $expected, ?string $expectedRetryAfter): void
    {
        $headersWithCt = array_merge(['Content-Type' => 'application/json'], $headers);
        $this->mockClient->addResponse(
            (new Response($status, $headersWithCt))
                ->withBody($this->factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame($expected, $err->providerCode);
            if ($expectedRetryAfter !== null) {
                $this->assertStringContainsString("Retry-After: {$expectedRetryAfter} seconds", (string) $err->providerMessage);
            }
        }
    }

    private function basicInput(): SmsSendInput
    {
        return new SmsSendInput(to: '+15551234567', body: 'hi', from: '+15550000000');
    }

    private function buildConnector(): TwilioSmsConnector
    {
        return new TwilioSmsConnector(
            new TwilioConfig(accountSid: 'ACsid', authToken: 'tok', from: '+15550000000'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return $this->jsonResponse(201, ['sid' => 'SM']);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function jsonResponse(int $status, array $body): Response
    {
        return (new Response($status, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
