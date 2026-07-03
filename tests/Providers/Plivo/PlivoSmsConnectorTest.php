<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Plivo;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Plivo\PlivoConfig;
use Thinwrap\Notifications\Providers\Plivo\PlivoNarrowedInput;
use Thinwrap\Notifications\Providers\Plivo\PlivoSmsConnector;

final class PlivoSmsConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathFirstUuidIsMessageId(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(202, ['api_id' => 'a', 'message' => 'queued', 'message_uuid' => ['u1', 'u2']])
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('u1', $result->providerMessageId);
    }

    public function testUrlWithTrailingSlashAndAccountPath(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://api.plivo.com/v1/Account/MAauthid/Message/', (string) $request->getUri());
    }

    public function testBasicAuth(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Basic ' . base64_encode('MAauthid:tok'), $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testBodyMappingSrcDstText(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('+15550000000', $decoded['src']);
        $this->assertSame('+15551234567', $decoded['dst']);
        $this->assertSame('hi', $decoded['text']);
    }

    public function testDltFields(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new PlivoNarrowedInput(
            to: '+15551234567',
            body: 'hi',
            from: '+15550000000',
            dltEntityId: 'E1',
            dltTemplateId: 'T1',
            dltTemplateCategory: 'transactional',
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('E1', $decoded['dlt_entity_id']);
        $this->assertSame('T1', $decoded['dlt_template_id']);
        $this->assertSame('transactional', $decoded['dlt_template_category']);
    }

    public function testMissingFromAndPowerpackThrows(): void
    {
        $connector = new PlivoSmsConnector(
            new PlivoConfig(authId: 'MAauthid', authToken: 'tok'),
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
            (new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '15']))
                ->withBody($this->factory->createStream(json_encode(['error' => 'rate'], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();
        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 15 seconds', (string) $err->providerMessage);
        }
    }

    private function basicInput(): SmsSendInput
    {
        return new SmsSendInput(to: '+15551234567', body: 'hi', from: '+15550000000');
    }

    private function buildConnector(): PlivoSmsConnector
    {
        return new PlivoSmsConnector(
            new PlivoConfig(authId: 'MAauthid', authToken: 'tok', from: '+15550000000'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return $this->jsonResponse(202, ['api_id' => 'a', 'message' => 'queued', 'message_uuid' => ['u']]);
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
