<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Messagebird;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Messagebird\MessagebirdConfig;
use Thinwrap\Notifications\Providers\Messagebird\MessagebirdNarrowedInput;
use Thinwrap\Notifications\Providers\Messagebird\MessagebirdSmsConnector;

final class MessagebirdSmsConnectorTest extends TestCase
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
        $this->mockClient->addResponse($this->jsonResponse(201, ['id' => 'mb-1']));
        $result = $this->buildConnector()->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('mb-1', $result->providerMessageId);
    }

    public function testAccessKeyAuth(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('AccessKey k', $request->getHeaderLine('Authorization'));
    }

    public function testRecipientsIsArray(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['+15551234567'], $decoded['recipients']);
        $this->assertSame('Sender', $decoded['originator']);
    }

    public function testDataCodingMapsToDatacodingWire(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $input = new MessagebirdNarrowedInput(
            to: '+15551234567',
            body: 'hi',
            from: 'Sender',
            dataCoding: 'unicode',
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('unicode', $decoded['datacoding']);
        $this->assertArrayNotHasKey('dataCoding', $decoded);
    }

    public function testMissingFromThrows(): void
    {
        $connector = new MessagebirdSmsConnector(
            new MessagebirdConfig(accessKey: 'k'),
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

    public function testRetryAfter(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '20']))
                ->withBody($this->factory->createStream(json_encode(['errors' => [['description' => 'rate']]], JSON_THROW_ON_ERROR)))
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 20 seconds', (string) $err->providerMessage);
        }
    }

    private function basicInput(): SmsSendInput
    {
        return new SmsSendInput(to: '+15551234567', body: 'hi', from: 'Sender');
    }

    private function buildConnector(): MessagebirdSmsConnector
    {
        return new MessagebirdSmsConnector(
            new MessagebirdConfig(accessKey: 'k', from: 'Sender'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return $this->jsonResponse(201, ['id' => 'mb']);
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
