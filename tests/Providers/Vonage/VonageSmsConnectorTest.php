<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Vonage;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Vonage\VonageConfig;
use Thinwrap\Notifications\Providers\Vonage\VonageNarrowedInput;
use Thinwrap\Notifications\Providers\Vonage\VonageSmsConnector;

final class VonageSmsConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathReturnsSentResult(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(200, [
                'message-count' => '1',
                'messages' => [['status' => '0', 'message-id' => 'v-1']],
            ])
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('v-1', $result->providerMessageId);
    }

    public function testFormBodyWithApiCredentials(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $body = (string) $request->getBody();
        $this->assertStringContainsString('api_key=ak', $body);
        $this->assertStringContainsString('api_secret=as', $body);
        $this->assertStringContainsString('from=Sender', $body);
        $this->assertStringContainsString('to=15551234567', $body);
        $this->assertStringContainsString('text=hello', $body);
    }

    public function testMissingFromThrows(): void
    {
        $connector = new VonageSmsConnector(
            new VonageConfig(apiKey: 'ak', apiSecret: 'as'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        try {
            $connector->send(new SmsSendInput(to: '15551234567', body: 'hi'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    public function testNarrowedInputKebabCaseKeys(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $input = new VonageNarrowedInput(
            to: '15551234567',
            body: 'hi',
            from: 'Sender',
            clientRef: 'ref1',
            messageClass: 0,
            statusReportReq: 1,
            ttl: 60000,
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        $body = (string) $request->getBody();
        $this->assertStringContainsString('client-ref=ref1', $body);
        $this->assertStringContainsString('message-class=0', $body);
        $this->assertStringContainsString('status-report-req=1', $body);
        $this->assertStringContainsString('ttl=60000', $body);
    }

    public function testBodyStatusNonZeroThrows(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(200, [
                'message-count' => '1',
                'messages' => [['status' => '4', 'error-text' => 'Invalid credentials']],
            ])
        );
        $connector = $this->buildConnector();

        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::AuthFailed, $err->providerCode);
            $this->assertSame('Invalid credentials', $err->providerMessage);
        }
    }

    public function testBodyStatus1RateLimited(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(200, [
                'message-count' => '1',
                'messages' => [['status' => '1', 'error-text' => 'Throttled']],
            ])
        );
        $connector = $this->buildConnector();
        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
        }
    }

    public function testHttpRetryAfter(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Retry-After' => '30']))->withBody($this->factory->createStream('rate'))
        );
        $connector = $this->buildConnector();
        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 30 seconds', (string) $err->providerMessage);
            /** @var array<string,mixed> $cause */
            $cause = $err->cause;
            $this->assertSame('30', $cause['retryAfter']);
        }
    }

    private function basicInput(): SmsSendInput
    {
        return new SmsSendInput(to: '15551234567', body: 'hello', from: 'Sender');
    }

    private function buildConnector(): VonageSmsConnector
    {
        return new VonageSmsConnector(
            new VonageConfig(apiKey: 'ak', apiSecret: 'as', from: 'Sender'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return $this->jsonResponse(200, [
            'message-count' => '1',
            'messages' => [['status' => '0', 'message-id' => 'v']],
        ]);
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
