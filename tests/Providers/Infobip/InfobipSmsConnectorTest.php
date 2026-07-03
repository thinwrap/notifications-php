<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Infobip;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Infobip\InfobipConfig;
use Thinwrap\Notifications\Providers\Infobip\InfobipSmsConnector;

final class InfobipSmsConnectorTest extends TestCase
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
            $this->jsonResponse(200, ['messages' => [['messageId' => 'ib-1', 'to' => '+15551234567']]])
        );
        $result = $this->buildConnector()->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('ib-1', $result->providerMessageId);
    }

    public function testPerAccountBaseUrl(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://abc123.api.infobip.com/sms/2/text/advanced', (string) $request->getUri());
    }

    public function testAppAuthHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('App k', $request->getHeaderLine('Authorization'));
    }

    public function testNestedMessagesEnvelope(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Sender', $decoded['messages'][0]['from']);
        $this->assertSame([['to' => '+15551234567']], $decoded['messages'][0]['destinations']);
        $this->assertSame('hi', $decoded['messages'][0]['text']);
    }

    public function testMissingFromThrows(): void
    {
        $connector = new InfobipSmsConnector(
            new InfobipConfig(baseUrl: 'abc123.api.infobip.com', apiKey: 'k'),
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

    public function testServiceExceptionMapping(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(400, ['requestError' => ['serviceException' => ['messageId' => 'EC_INVALID', 'text' => 'bad number']]])
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            $this->assertSame('bad number', $err->providerMessage);
        }
    }

    public function testRetryAfter(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '5']))
                ->withBody($this->factory->createStream(json_encode(['requestError' => ['serviceException' => ['text' => 'rate']]], JSON_THROW_ON_ERROR)))
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 5 seconds', (string) $err->providerMessage);
        }
    }

    private function basicInput(): SmsSendInput
    {
        return new SmsSendInput(to: '+15551234567', body: 'hi', from: 'Sender');
    }

    private function buildConnector(): InfobipSmsConnector
    {
        return new InfobipSmsConnector(
            new InfobipConfig(baseUrl: 'abc123.api.infobip.com', apiKey: 'k', from: 'Sender'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return $this->jsonResponse(200, ['messages' => [['messageId' => 'm']]]);
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
