<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Textmagic;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Textmagic\TextmagicConfig;
use Thinwrap\Notifications\Providers\Textmagic\TextmagicNarrowedInput;
use Thinwrap\Notifications\Providers\Textmagic\TextmagicSmsConnector;

final class TextmagicSmsConnectorTest extends TestCase
{
    private MockClient $mockClient;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathIdStringified(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(201, ['id' => 99999, 'href' => '/messages/99999'])
        );
        $result = $this->buildConnector()->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('99999', $result->providerMessageId);
    }

    public function testTwoHeaderAuth(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('alice', $request->getHeaderLine('X-TM-Username'));
        $this->assertSame('k', $request->getHeaderLine('X-TM-Key'));
        $this->assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testFormBodyWithPhonesKey(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $body = (string) $request->getBody();
        $this->assertStringContainsString('text=hi', $body);
        $this->assertStringContainsString('phones=', $body);
    }

    public function testCutExtraEncodesAs10(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $input = new TextmagicNarrowedInput(
            to: '+15551234567',
            body: 'hi',
            from: 'Sender',
            cutExtra: true,
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        $body = (string) $request->getBody();
        $this->assertStringContainsString('cutExtra=1', $body);
    }

    public function test422MapsInvalidRecipient(): void
    {
        // Textmagic has no recipient error code; only a phone/recipient-phrased 422 escalates.
        $this->mockClient->addResponse(
            $this->jsonResponse(422, ['message' => 'Invalid phone number'])
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    public function testGeneric422MapsInvalidRequest(): void
    {
        // A bare 422 with no recipient phrasing is generic request-validation.
        $this->mockClient->addResponse(
            $this->jsonResponse(422, ['message' => 'unprocessable'])
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    public function test429RateLimitedWithRetryAfter(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '15']))
                ->withBody($this->factory->createStream(json_encode(['message' => 'rate'], JSON_THROW_ON_ERROR)))
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 15 seconds', (string) $err->providerMessage);
        }
    }

    private function basicInput(): SmsSendInput
    {
        return new SmsSendInput(to: '+15551234567', body: 'hi', from: 'Sender');
    }

    private function buildConnector(): TextmagicSmsConnector
    {
        return new TextmagicSmsConnector(
            new TextmagicConfig(username: 'alice', apiKey: 'k', from: 'Sender'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return $this->jsonResponse(201, ['id' => 1]);
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
