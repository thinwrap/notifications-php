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

    public function testRegionalHost(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new TwilioSmsConnector(
            new TwilioConfig(accountSid: 'ACsid', authToken: 'tok', from: '+15550000000', region: TwilioRegion::Ie1),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://api.ie1.twilio.com/2010-04-01/Accounts/ACsid/Messages.json', (string) $request->getUri());
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
