<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\D7networks;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\D7networks\D7networksConfig;
use Thinwrap\Notifications\Providers\D7networks\D7networksNarrowedInput;
use Thinwrap\Notifications\Providers\D7networks\D7networksSmsConnector;

final class D7networksSmsConnectorTest extends TestCase
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
            $this->jsonResponse(200, ['data' => ['request_id' => 'req_123']])
        );

        $input = new D7networksNarrowedInput(
            to: '+15551234567',
            body: 'hi',
            from: 'Sender',
        );
        $result = $this->buildConnector()->send($input);

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('req_123', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.d7networks.com/messages/v1/send', (string) $request->getUri());
        $this->assertSame('Bearer token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        /** @var array{messages:list<array<string,mixed>>,message_globals:array<string,mixed>} $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($body['messages']);
        $this->assertCount(1, $body['messages']);
        $msg = $body['messages'][0];
        $this->assertSame('sms', $msg['channel']);
        $this->assertSame(['+15551234567'], $msg['recipients']);
        $this->assertSame('hi', $msg['content']);
        $this->assertSame('text', $msg['msg_type']);

        $this->assertIsArray($body['message_globals']);
        $this->assertSame('Sender', $body['message_globals']['originator']);
    }

    public function testOriginatorPrecedence(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(200, ['request_id' => 'r'])
        );

        $input = new D7networksNarrowedInput(
            to: '+1',
            body: 'hi',
            from: 'IgnoreMe',
            originator: 'AlphaSender',
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array{message_globals:array<string,mixed>} $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('AlphaSender', $body['message_globals']['originator']);
    }

    public function testMissingOriginatorThrowsBeforeHttp(): void
    {
        $connector = new D7networksSmsConnector(
            new D7networksConfig(apiToken: 'token', from: null),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        try {
            $connector->send(new SmsSendInput(to: '+1', body: 'hi'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    public function test401MapsAuthFailed(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(401, ['code' => 'AUTH', 'detail' => 'bad token'])
        );

        try {
            $this->buildConnector()->send(new SmsSendInput(to: '+1', body: 'hi', from: 'S'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::AuthFailed, $err->providerCode);
            $this->assertSame('bad token', $err->providerMessage);
        }
    }

    public function test429RateLimitedWithRetryAfter(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '90']))
                ->withBody($this->factory->createStream(json_encode(['code' => 'RL', 'detail' => 'rate'], JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new SmsSendInput(to: '+1', body: 'hi', from: 'S'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 90 seconds', (string) $err->providerMessage);
            /** @var array{retryAfter?:string|null} $cause */
            $cause = $err->cause;
            $this->assertSame('90', $cause['retryAfter'] ?? null);
        }
    }

    public function test500MapsProviderUnavailable(): void
    {
        $this->mockClient->addResponse($this->jsonResponse(500, []));

        try {
            $this->buildConnector()->send(new SmsSendInput(to: '+1', body: 'hi', from: 'S'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::ProviderUnavailable, $err->providerCode);
        }
    }

    public function test422InvalidRequestPreservesDetail(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(422, ['code' => 'INVALID_PHONE', 'detail' => 'bad number'])
        );

        try {
            $this->buildConnector()->send(new SmsSendInput(to: '+1', body: 'hi', from: 'S'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            $this->assertSame('bad number', $err->providerMessage);
        }
    }

    public function testPassthroughDeepMergeAndHeaders(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(200, ['request_id' => 'r'])
        );

        $input = new D7networksNarrowedInput(
            to: '+1',
            body: 'hi',
            from: 'Sender',
            _passthrough: [
                'body' => [
                    'message_globals' => ['tag' => 'override'],
                ],
                'headers' => ['X-Idempotency-Key' => 'key-1'],
            ],
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('key-1', $request->getHeaderLine('X-Idempotency-Key'));

        /** @var array{message_globals:array<string,mixed>} $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('override', $body['message_globals']['tag']);
        $this->assertSame('Sender', $body['message_globals']['originator']);
    }

    private function buildConnector(): D7networksSmsConnector
    {
        return new D7networksSmsConnector(
            new D7networksConfig(apiToken: 'token', from: 'Sender'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
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
