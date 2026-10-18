<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Telnyx;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Telnyx\TelnyxConfig;
use Thinwrap\Notifications\Providers\Telnyx\TelnyxSmsConnector;

final class TelnyxSmsConnectorTest extends TestCase
{
    private MockClient $mockClient;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathUnwrapsDataEnvelope(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(200, ['data' => ['id' => 'tx-1']])
        );
        $result = $this->buildConnector()->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('tx-1', $result->providerMessageId);
    }

    public function testEndpointAndBearer(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://api.telnyx.com/v2/messages', (string) $request->getUri());
        $this->assertSame('Bearer k', $request->getHeaderLine('Authorization'));
    }

    public function testTextNotBodyWireKey(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('hi', $decoded['text']);
        $this->assertArrayNotHasKey('body', $decoded);
    }

    public function testMissingFromAndProfileThrows(): void
    {
        $connector = new TelnyxSmsConnector(
            new TelnyxConfig(apiKey: 'k'),
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

    public function testInvalidToCodeMapsRecipient(): void
    {
        // Telnyx messaging error 40310 "Invalid 'to' address"; codes are numeric strings.
        $this->mockClient->addResponse(
            $this->jsonResponse(400, ['errors' => [['code' => '40310', 'title' => "Invalid 'to' address", 'detail' => 'bad number']]])
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
            $this->assertSame('bad number', $err->providerMessage);
        }
    }

    /**
     * Telnyx api-errors (`{"errors":[{"code":"10001","title":…,"detail":…,"source":{}}]}`)
     * and messaging error codes (403xx synchronous; help-centre titles).
     *
     * @return iterable<string,array{int,array<string,mixed>,ProviderCode}>
     */
    public static function vendorCodes(): iterable
    {
        yield '40300 Blocked due to STOP message' => [422, ['code' => '40300'], ProviderCode::InvalidRecipient];
        yield '40305 Invalid from address' => [422, ['code' => '40305'], ProviderCode::InvalidRequest];
        yield '40311 Invalid messaging profile secret' => [422, ['code' => '40311'], ProviderCode::AuthFailed];
        yield '40314 Messaging disabled on account' => [422, ['code' => '40314'], ProviderCode::InvalidRequest];
        yield '40318 Message queue full' => [422, ['code' => '40318'], ProviderCode::RateLimited];
        yield '10009 Authentication failed' => [422, ['code' => '10009'], ProviderCode::AuthFailed];
        yield '10011 Too many requests' => [400, ['code' => '10011'], ProviderCode::RateLimited];
        yield '20100 Insufficient Funds' => [400, ['code' => '20100'], ProviderCode::InvalidRequest];
        yield '10002 Invalid phone number at /to' => [422, ['code' => '10002', 'source' => ['pointer' => '/to']], ProviderCode::InvalidRecipient];
        yield '10002 Invalid phone number elsewhere' => [422, ['code' => '10002', 'source' => ['pointer' => '/from']], ProviderCode::InvalidRequest];
        yield 'integer code is read too' => [422, ['code' => 40310], ProviderCode::InvalidRecipient];
    }

    /**
     * @param array<string,mixed> $error
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('vendorCodes')]
    public function testVendorCodeMapping(int $status, array $error, ProviderCode $expected): void
    {
        $this->mockClient->addResponse($this->jsonResponse($status, ['errors' => [$error]]));
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame($expected, $err->providerCode);
        }
    }

    public function testGeneric422MapsInvalidRequest(): void
    {
        // A bare 422 with no recipient-specific code is generic request-validation.
        $this->mockClient->addResponse(
            $this->jsonResponse(422, ['errors' => [['code' => 'other', 'detail' => 'unprocessable']]])
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    public function testRetryAfter(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '10']))
                ->withBody($this->factory->createStream(json_encode(['errors' => [['detail' => 'rate']]], JSON_THROW_ON_ERROR)))
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 10 seconds', (string) $err->providerMessage);
        }
    }

    private function basicInput(): SmsSendInput
    {
        return new SmsSendInput(to: '+15551234567', body: 'hi', from: '+15550000000');
    }

    private function buildConnector(): TelnyxSmsConnector
    {
        return new TelnyxSmsConnector(
            new TelnyxConfig(apiKey: 'k', from: '+15550000000'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return $this->jsonResponse(200, ['data' => ['id' => 'tx']]);
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
