<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\WhatsappBusiness;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\WhatsappBusiness\WhatsappBusinessChatConnector;
use Thinwrap\Notifications\Providers\WhatsappBusiness\WhatsappBusinessChatSendInput;
use Thinwrap\Notifications\Providers\WhatsappBusiness\WhatsappBusinessConfig;

final class WhatsappBusinessChatConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathTextMessage(): void
    {
        $payload = ['messages' => [['id' => 'wamid.ABC']]];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $result = $this->buildConnector()->send(new ChatSendInput(to: '+14155552671', body: 'hi'));
        $this->assertTrue($result->success);
        $this->assertSame(ChatStatus::Sent, $result->status);
        $this->assertSame('wamid.ABC', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Bearer tk', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('/v21.0/phone-1/messages', (string) $request->getUri());

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('whatsapp', $decoded['messaging_product']);
        $this->assertSame('individual', $decoded['recipient_type']);
        $this->assertSame('+14155552671', $decoded['to']);
        $this->assertSame('text', $decoded['type']);
        $this->assertSame('hi', $decoded['text']['body']);
    }

    public function testTemplateMessage(): void
    {
        $payload = ['messages' => [['id' => 'wamid.X']]];
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $input = new WhatsappBusinessChatSendInput(
            to: '+1',
            body: 'ignored',
            type: 'template',
            template: ['name' => 'order_update', 'language' => ['code' => 'en']],
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('template', $decoded['type']);
        $this->assertSame('order_update', $decoded['template']['name']);
        $this->assertArrayNotHasKey('text', $decoded);
    }

    public function testMissingToThrows(): void
    {
        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    public function testAuthCode190MapsAuthFailed(): void
    {
        $payload = ['error' => ['code' => 190, 'message' => 'invalid token']];
        $this->mockClient->addResponse(
            (new Response(401, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: '+1', body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::AuthFailed, $err->providerCode);
        }
    }

    public function testInvalidRecipient131026(): void
    {
        $payload = ['error' => ['code' => 131026, 'message' => 'Message undeliverable']];
        $this->mockClient->addResponse(
            (new Response(400, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: '+1', body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    /**
     * Cloud API error codes (developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes).
     *
     * @return iterable<string,array{int,int,ProviderCode}>
     */
    public static function cloudApiCodes(): iterable
    {
        yield '131008 "The request is missing a required parameter."' => [400, 131008, ProviderCode::InvalidRequest];
        yield '131047 "More than 24 hours have passed since recipient last replied"' => [400, 131047, ProviderCode::InvalidRequest];
        yield '133010 "Phone number not registered on the WhatsApp Business Platform."' => [400, 133010, ProviderCode::InvalidRequest];
        yield '131056 pair rate limit' => [400, 131056, ProviderCode::RateLimited];
        yield '130429 "Rate limit hit"' => [400, 130429, ProviderCode::RateLimited];
        yield '131048 spam rate limit' => [400, 131048, ProviderCode::RateLimited];
        yield '0 "unable to authenticate the app user"' => [400, 0, ProviderCode::AuthFailed];
        yield '10 "Permission is either not granted or has been removed"' => [400, 10, ProviderCode::AuthFailed];
        yield '200-299 permission' => [400, 230, ProviderCode::AuthFailed];
        yield '131021 sender == recipient' => [400, 131021, ProviderCode::InvalidRecipient];
        yield '131016 "A service is temporarily unavailable."' => [400, 131016, ProviderCode::ProviderUnavailable];
        yield 'unlisted code on 403 falls back to the status' => [403, 999999, ProviderCode::AuthFailed];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cloudApiCodes')]
    public function testCloudApiErrorCodes(int $status, int $code, ProviderCode $expected): void
    {
        $payload = ['error' => ['code' => $code, 'message' => 'x', 'fbtrace_id' => 't']];
        $this->mockClient->addResponse(
            (new Response($status, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: '+1', body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame($expected, $err->providerCode);
        }
    }

    private function buildConnector(): WhatsappBusinessChatConnector
    {
        return new WhatsappBusinessChatConnector(
            new WhatsappBusinessConfig(accessToken: 'tk', phoneNumberId: 'phone-1', graphApiVersion: 'v21.0'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
