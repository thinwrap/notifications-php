<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Telegram;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Chat\ChatSendInput;
use Thinwrap\Notifications\DTO\Chat\ChatStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Telegram\TelegramChatConnector;
use Thinwrap\Notifications\Providers\Telegram\TelegramChatSendInput;
use Thinwrap\Notifications\Providers\Telegram\TelegramConfig;

final class TelegramChatConnectorTest extends TestCase
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
        $payload = ['ok' => true, 'result' => ['message_id' => 42]];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $result = $this->buildConnector()->send(new ChatSendInput(to: '@chan', body: 'hi'));
        $this->assertTrue($result->success);
        $this->assertSame(ChatStatus::Sent, $result->status);
        $this->assertSame('42', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertStringContainsString('/bottoken-x/sendMessage', (string) $request->getUri());
    }

    public function testNarrowedFieldsSnakeCaseMapping(): void
    {
        $payload = ['ok' => true, 'result' => ['message_id' => 1]];
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $input = new TelegramChatSendInput(
            to: '@chan',
            body: 'hi',
            parseMode: 'MarkdownV2',
            disableNotification: true,
            protectContent: false,
            messageThreadId: 99,
        );
        $this->buildConnector()->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('MarkdownV2', $decoded['parse_mode']);
        $this->assertTrue($decoded['disable_notification']);
        $this->assertFalse($decoded['protect_content']);
        $this->assertSame(99, $decoded['message_thread_id']);
    }

    public function testMissingToThrowsInvalidRequest(): void
    {
        try {
            $this->buildConnector()->send(new ChatSendInput(to: null, body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    public function testSoftErrorBodyMapsViaParametersRetryAfter(): void
    {
        $payload = [
            'ok' => false, 'error_code' => 429,
            'description' => 'Too Many Requests: retry after 5',
            'parameters' => ['retry_after' => 5],
        ];
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: '@c', body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 5 seconds', (string) $err->providerMessage);
            /** @var array<string,mixed> $cause */
            $cause = $err->cause;
            $this->assertSame(5, $cause['retryAfter'] ?? null);
        }
    }

    public function testChatNotFoundMapsInvalidRecipient(): void
    {
        $payload = ['ok' => false, 'description' => 'Bad Request: chat not found'];
        $this->mockClient->addResponse(
            (new Response(400, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new ChatSendInput(to: '@no', body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    /**
     * telegram-bot-api Client.cpp: USER_IS_BLOCKED → `403 "Forbidden: bot was
     * blocked by the user"`; ClientManager.cpp: `401 "Unauthorized: invalid
     * token specified"`; a malformed `/bot<token>/` path is 404 "Not Found".
     *
     * @return iterable<string,array{int,string,ProviderCode}>
     */
    public static function botApiStatuses(): iterable
    {
        yield '403 blocked by the user' => [403, 'Forbidden: bot was blocked by the user', ProviderCode::InvalidRecipient];
        yield '403 kicked from the group' => [403, 'Forbidden: bot was kicked from the group chat', ProviderCode::InvalidRecipient];
        yield '401 invalid token' => [401, 'Unauthorized: invalid token specified', ProviderCode::AuthFailed];
        yield '404 malformed token path' => [404, 'Not Found', ProviderCode::AuthFailed];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('botApiStatuses')]
    public function testBotApiStatusMapping(int $status, string $description, ProviderCode $expected): void
    {
        $this->mockClient->addResponse(
            (new Response($status, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode(['ok' => false, 'error_code' => $status, 'description' => $description], JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send(new \Thinwrap\Notifications\DTO\Chat\ChatSendInput(to: '123', body: 'x'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame($expected, $err->providerCode);
        }
    }

    /**
     * Bot API: `https://api.telegram.org/bot<token>/METHOD_NAME`, token
     * `123456:ABC-DEF…`. The colon stays literal; anything that could leave the
     * path segment is escaped.
     */
    public function testBotTokenKeepsItsColonAndIsOtherwisePathEscaped(): void
    {
        $this->mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"result":{"message_id":1}}'));
        $this->mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"result":{"message_id":2}}'));

        foreach (['123456:ABC-def_x', '1:a/../evil?x#'] as $token) {
            (new TelegramChatConnector(new TelegramConfig(botToken: $token), $this->mockClient, $this->factory, $this->factory))
                ->send(new ChatSendInput(to: '1', body: 'x'));
        }

        $requests = $this->mockClient->getRequests();
        $this->assertSame('https://api.telegram.org/bot123456:ABC-def_x/sendMessage', (string) $requests[0]->getUri());
        $this->assertSame('https://api.telegram.org/bot1:a%2F..%2Fevil%3Fx%23/sendMessage', (string) $requests[1]->getUri());
    }

    private function buildConnector(): TelegramChatConnector
    {
        return new TelegramChatConnector(
            new TelegramConfig(botToken: 'token-x'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
