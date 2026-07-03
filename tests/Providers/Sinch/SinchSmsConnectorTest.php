<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Sinch;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Sinch\SinchConfig;
use Thinwrap\Notifications\Providers\Sinch\SinchRegion;
use Thinwrap\Notifications\Providers\Sinch\SinchSmsConnector;

final class SinchSmsConnectorTest extends TestCase
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
            $this->jsonResponse(201, ['id' => 'batch-1', 'to' => ['+15551234567'], 'from' => 'sender'])
        );
        $result = $this->buildConnector()->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('batch-1', $result->providerMessageId);
    }

    public function testToIsWrappedInArray(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['+15551234567'], $decoded['to']);
    }

    public function testUsRegionDefault(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertStringStartsWith('https://us.sms.api.sinch.com/xms/v1/SP1/batches', (string) $request->getUri());
    }

    public function testEuRegion(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new SinchSmsConnector(
            new SinchConfig(servicePlanId: 'SP1', apiToken: 't', from: 'sender', region: SinchRegion::Eu),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertStringStartsWith('https://eu.sms.api.sinch.com/xms/v1/SP1/batches', (string) $request->getUri());
    }

    public function testBearerAuth(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());
        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Bearer t', $request->getHeaderLine('Authorization'));
    }

    public function testMissingFromThrows(): void
    {
        $connector = new SinchSmsConnector(
            new SinchConfig(servicePlanId: 'SP1', apiToken: 't'),
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

    public function testInvalidDestinationCode(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(400, ['code' => 'invalid_destination', 'text' => 'bad number'])
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    private function basicInput(): SmsSendInput
    {
        return new SmsSendInput(to: '+15551234567', body: 'hi', from: 'sender');
    }

    private function buildConnector(): SinchSmsConnector
    {
        return new SinchSmsConnector(
            new SinchConfig(servicePlanId: 'SP1', apiToken: 't', from: 'sender'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return $this->jsonResponse(201, ['id' => 'b', 'to' => ['+15551234567'], 'from' => 'sender']);
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
