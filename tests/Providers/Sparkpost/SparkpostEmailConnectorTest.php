<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Sparkpost;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Sparkpost\SparkpostConfig;
use Thinwrap\Notifications\Providers\Sparkpost\SparkpostEmailConnector;
use Thinwrap\Notifications\Providers\Sparkpost\SparkpostRegion;

final class SparkpostEmailConnectorTest extends TestCase
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
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode([
                    'results' => ['id' => 'sp-1', 'total_accepted_recipients' => 1, 'total_rejected_recipients' => 0],
                ], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Queued, $result->status);
        $this->assertSame('sp-1', $result->providerMessageId);
    }

    public function testAuthHeaderRawNoBearer(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('k', $request->getHeaderLine('Authorization'));
    }

    public function testRegionalEuEndpoint(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new SparkpostEmailConnector(
            new SparkpostConfig(apiKey: 'k', from: 'sender@example.com', region: SparkpostRegion::Eu),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://api.eu.sparkpost.com/api/v1/transmissions', (string) $request->getUri());
    }

    public function testCcBccPackedIntoRecipientsAndHeaders(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'primary@x',
            subject: 'Hi',
            cc: ['c1@x', 'c2@x'],
            bcc: ['b@x'],
            text: 'hi',
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        // 1 primary + 2 cc + 1 bcc = 4 recipients.
        $this->assertCount(4, $decoded['recipients']);
        $this->assertSame(['email' => 'primary@x'], $decoded['recipients'][0]['address']);
        $this->assertSame('primary@x', $decoded['recipients'][1]['address']['header_to']);
        $this->assertSame('primary@x', $decoded['recipients'][3]['address']['header_to']);
        // CC visibility — CC header present.
        $this->assertSame('c1@x, c2@x', $decoded['content']['headers']['CC']);
        // BCC stays invisible — never written to headers.BCC.
        $this->assertArrayNotHasKey('BCC', $decoded['content']['headers']);
    }

    public function testRejectAllRecipientsThrowsInvalidRecipient(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode([
                    'results' => ['id' => 'sp-1', 'total_accepted_recipients' => 0, 'total_rejected_recipients' => 1],
                ], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['errors' => [['message' => 'unauth']]], [], ProviderCode::AuthFailed, null];
        yield '420' => [420, ['errors' => [['message' => 'sending suspended']]], [], ProviderCode::AuthFailed, null];
        yield '429 retry' => [429, ['errors' => [['message' => 'rate']]], ['Retry-After' => '60'], ProviderCode::RateLimited, '60'];
        yield '500' => [500, ['errors' => [['message' => 'oops']]], [], ProviderCode::ProviderUnavailable, null];
        yield '400 recipient' => [400, ['errors' => [['message' => 'invalid recipient address']]], [], ProviderCode::InvalidRecipient, null];
        yield '400 code 1300' => [400, ['errors' => [['message' => 'x', 'code' => '1300']]], [], ProviderCode::InvalidRecipient, null];
        yield '400 other' => [400, ['errors' => [['message' => 'invalid template']]], [], ProviderCode::InvalidRequest, null];
        yield '422' => [422, ['errors' => [['message' => 'validation']]], [], ProviderCode::InvalidRequest, null];
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

    private function basicInput(): EmailSendInput
    {
        return new EmailSendInput(
            from: 'sender@example.com',
            to: 'r@x',
            subject: 'Hi',
            text: 'hi',
        );
    }

    private function buildConnector(): SparkpostEmailConnector
    {
        return new SparkpostEmailConnector(
            new SparkpostConfig(apiKey: 'k', from: 'sender@example.com'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return (new Response(200, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode([
                'results' => ['id' => 'sp', 'total_accepted_recipients' => 1, 'total_rejected_recipients' => 0],
            ], JSON_THROW_ON_ERROR)));
    }
}
