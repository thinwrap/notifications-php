<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Scaleway;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Scaleway\ScalewayConfig;
use Thinwrap\Notifications\Providers\Scaleway\ScalewayEmailConnector;
use Thinwrap\Notifications\Providers\Scaleway\ScalewayRegion;

final class ScalewayEmailConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathQueuedWithMessageId(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $result = $this->buildConnector()->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Queued, $result->status);
        $this->assertSame('msg-1', $result->providerMessageId);
    }

    public function testDefaultRegionPathAndAuthHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame(
            'https://api.scaleway.com/transactional-email/v1alpha1/regions/fr-par/emails',
            (string) $request->getUri(),
        );
        $this->assertSame('scw-secret', $request->getHeaderLine('X-Auth-Token'));
        $this->assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testRegionInterpolatedIntoPath(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new ScalewayEmailConnector(
            new ScalewayConfig(secretKey: 'scw-secret', projectId: 'proj', from: 'sender@example.com', region: ScalewayRegion::FrPar),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertStringContainsString('/regions/fr-par/emails', (string) $request->getUri());
    }

    /** Any enum region is passed through; which regions serve TEM is Scaleway's call. */
    public function testOtherRegionsArePassedThrough(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new ScalewayEmailConnector(
            new ScalewayConfig(secretKey: 'scw-secret', projectId: 'proj', from: 'sender@example.com', region: ScalewayRegion::PlWaw),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $connector->send($this->basicInput());

        $this->assertStringContainsString('/regions/pl-waw/emails', (string) $this->mockClient->getRequests()[0]->getUri());
    }

    public function testBodyShapeFromToProjectIdAndSenderName(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new ScalewayEmailConnector(
            new ScalewayConfig(secretKey: 'scw-secret', projectId: 'proj-abc', from: 'sender@example.com', senderName: 'Acme'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send(new EmailSendInput(
            from: 'override@example.com',
            to: 'a@x',
            subject: 'Hello',
            text: 'Hi',
            html: '<p>Hi</p>',
        ));

        $decoded = $this->decodeRequestBody();
        $this->assertSame(['email' => 'override@example.com', 'name' => 'Acme'], $decoded['from']);
        $this->assertSame([['email' => 'a@x']], $decoded['to']);
        $this->assertSame('proj-abc', $decoded['project_id']);
        $this->assertSame('Hello', $decoded['subject']);
        $this->assertSame('Hi', $decoded['text']);
        $this->assertSame('<p>Hi</p>', $decoded['html']);
    }

    public function testCcBccAsAddressArrays(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'a@x',
            subject: 'S',
            cc: ['c@y'],
            bcc: ['b@z'],
            text: 'hi',
        ));

        $decoded = $this->decodeRequestBody();
        $this->assertSame([['email' => 'c@y']], $decoded['cc']);
        $this->assertSame([['email' => 'b@z']], $decoded['bcc']);
    }

    public function testAdditionalHeadersAndReplyTo(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'a@x',
            subject: 'S',
            replyTo: 'reply@example.com',
            text: 'hi',
            headers: ['X-Custom' => 'v1'],
        ));

        $decoded = $this->decodeRequestBody();
        $this->assertSame([
            ['key' => 'Reply-To', 'value' => 'reply@example.com'],
            ['key' => 'X-Custom', 'value' => 'v1'],
        ], $decoded['additional_headers']);
    }

    public function testAttachmentsBase64AndDefaultMime(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'a@x',
            subject: 'S',
            text: 'hi',
            attachments: [
                new EmailAttachment(filename: 'a.txt', content: 'hello', contentType: 'text/plain'),
                new EmailAttachment(filename: 'b.bin', content: 'data'),
            ],
        ));

        $decoded = $this->decodeRequestBody();
        $this->assertSame([
            ['name' => 'a.txt', 'type' => 'text/plain', 'content' => base64_encode('hello')],
            ['name' => 'b.bin', 'type' => 'application/octet-stream', 'content' => base64_encode('data')],
        ], $decoded['attachments']);
    }

    public function testTagsAndContentIdDroppedSilently(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'a@x',
            subject: 'S',
            text: 'hi',
            attachments: [new EmailAttachment(filename: 'a.png', content: 'x', contentType: 'image/png', contentId: 'cid1')],
            tags: ['t1', 't2'],
        ));

        $decoded = $this->decodeRequestBody();
        $this->assertArrayNotHasKey('tags', $decoded);
        $this->assertArrayNotHasKey('contentId', $decoded['attachments'][0]);
        $this->assertArrayNotHasKey('content_id', $decoded['attachments'][0]);
    }

    public function testEmptyEmailsArrayIsStillSuccessWithNullId(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode(['emails' => []], JSON_THROW_ON_ERROR)))
        );

        $result = $this->buildConnector()->send($this->basicInput());
        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Queued, $result->status);
        $this->assertNull($result->providerMessageId);
    }

    public function testPassthroughBodyMerged(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'a@x',
            subject: 'S',
            text: 'hi',
            _passthrough: ['body' => ['scheduled_at' => '2026-06-03T00:00:00Z']],
        ));

        $decoded = $this->decodeRequestBody();
        $this->assertSame('2026-06-03T00:00:00Z', $decoded['scheduled_at']);
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '400' => [400, ['message' => 'bad request', 'type' => 'invalid_arguments'], [], ProviderCode::InvalidRequest, null];
        yield '401' => [401, ['message' => 'unauth'], [], ProviderCode::AuthFailed, null];
        yield '403' => [403, ['message' => 'forbidden'], [], ProviderCode::AuthFailed, null];
        yield '404' => [404, ['message' => 'not found'], [], ProviderCode::InvalidRequest, null];
        yield '429 retry' => [429, ['message' => 'rate'], ['Retry-After' => '30'], ProviderCode::RateLimited, '30'];
        yield '500' => [500, ['message' => 'oops'], [], ProviderCode::ProviderUnavailable, null];
        yield 'errors variant' => [400, ['errors' => [['message' => 'bad field']]], [], ProviderCode::InvalidRequest, null];
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

        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame($expected, $err->providerCode);
            $this->assertSame($status, $err->statusCode);
            if ($expectedRetryAfter !== null) {
                $this->assertStringContainsString("Retry-After: {$expectedRetryAfter} seconds", (string) $err->providerMessage);
                /** @var array<string,mixed> $cause */
                $cause = $err->cause;
                $this->assertSame($expectedRetryAfter, $cause['retryAfter']);
                $this->assertSame((int) $expectedRetryAfter, $cause['retryAfterSeconds']);
            }
        }
    }

    private function basicInput(): EmailSendInput
    {
        return new EmailSendInput(from: 'sender@example.com', to: 'r@x', subject: 'Hi', text: 'hi');
    }

    private function buildConnector(): ScalewayEmailConnector
    {
        return new ScalewayEmailConnector(
            new ScalewayConfig(secretKey: 'scw-secret', projectId: 'proj', from: 'sender@example.com'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return (new Response(200, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode([
                'emails' => [['id' => 'eml-1', 'message_id' => 'msg-1', 'status' => 'new']],
            ], JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeRequestBody(): array
    {
        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
