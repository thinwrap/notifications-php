<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Postmark;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Postmark\PostmarkConfig;
use Thinwrap\Notifications\Providers\Postmark\PostmarkEmailConnector;

final class PostmarkEmailConnectorTest extends TestCase
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
                    'MessageID' => 'abc',
                    'ErrorCode' => 0,
                    'Message' => 'OK',
                    'To' => 'r@x',
                    'SubmittedAt' => '2026-05-01T00:00:00Z',
                ], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Sent, $result->status);
        $this->assertSame('abc', $result->providerMessageId);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('test-token', $request->getHeaderLine('X-Postmark-Server-Token'));
        $this->assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testSoftRejectOn200WithErrorCode(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode([
                    'MessageID' => 'soft',
                    'ErrorCode' => 300,
                    'Message' => 'Inactive recipient',
                ], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertFalse($result->success);
        $this->assertSame(EmailStatus::Rejected, $result->status);
        /** @var array<string,mixed> $raw */
        $raw = $result->raw;
        $this->assertSame('Inactive recipient', $raw['Message']);
    }

    public function testSingleTagNormalizationThreeTags(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            tags: ['a', 'b', 'c'],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('a', $decoded['Tag']);
        $this->assertArrayNotHasKey('Tags', $decoded);
    }

    public function testNoTags(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());
        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('Tag', $decoded);
    }

    public function testHeadersRecordToArray(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            headers: ['X-Foo' => '1', 'X-Bar' => '2'],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [['Name' => 'X-Foo', 'Value' => '1'], ['Name' => 'X-Bar', 'Value' => '2']],
            $decoded['Headers'],
        );
    }

    public function testMessageStreamPropagation(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new PostmarkEmailConnector(
            new PostmarkConfig(serverToken: 't', from: 's@x', messageStream: 'broadcasts'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('broadcasts', $decoded['MessageStream']);
    }

    public function testPassthroughHonored(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            _passthrough: [
                'body' => ['Metadata' => ['x' => 'y']],
                'headers' => ['X-Idempotency-Key' => 'idem-1'],
            ],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['x' => 'y'], $decoded['Metadata']);
        $this->assertSame('idem-1', $request->getHeaderLine('X-Idempotency-Key'));
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401 BadOrMissingApiToken' => [401, ['ErrorCode' => 10, 'Message' => 'Bad token'], [], ProviderCode::AuthFailed, null];
        // Postmark error-code table (developer/api/overview#error-codes).
        yield '300 send validation naming the address' => [422, ['ErrorCode' => 300, 'Message' => "Error parsing 'To': Illegal email address 'x'."], [], ProviderCode::InvalidRecipient, null];
        yield '300 send validation, not the recipient' => [422, ['ErrorCode' => 300, 'Message' => 'Provide either email TextBody or HtmlBody or both.'], [], ProviderCode::InvalidRequest, null];
        yield '300 zero recipients' => [422, ['ErrorCode' => 300, 'Message' => 'Zero recipients specified'], [], ProviderCode::InvalidRequest, null];
        yield '300 bad From address' => [422, ['ErrorCode' => 300, 'Message' => "Error parsing 'From': Illegal email address 'x'."], [], ProviderCode::InvalidRequest, null];
        yield '300 bad Cc address' => [422, ['ErrorCode' => 300, 'Message' => "Invalid 'Cc' address: 'a..b@example.com'."], [], ProviderCode::InvalidRecipient, null];
        yield '300 bad Reply-To address' => [422, ['ErrorCode' => 300, 'Message' => "Invalid 'Reply-To' address."], [], ProviderCode::InvalidRequest, null];
        yield '300 bad Cc address that contains "from"' => [422, ['ErrorCode' => 300, 'Message' => "Error parsing 'Cc': Illegal email address 'from@x'."], [], ProviderCode::InvalidRecipient, null];
        yield '406 "Inactive recipient."' => [422, ['ErrorCode' => 406, 'Message' => 'Inactive'], [], ProviderCode::InvalidRecipient, null];
        yield '411 "Attachment file type not allowed."' => [422, ['ErrorCode' => 411, 'Message' => 'Forbidden type'], [], ProviderCode::InvalidRequest, null];
        yield '412 account pending: recipients must share the From domain' => [422, ['ErrorCode' => 412, 'Message' => 'Pending'], [], ProviderCode::AuthFailed, null];
        yield '413 "This account is not approved to send email."' => [422, ['ErrorCode' => 413, 'Message' => 'Not approved'], [], ProviderCode::AuthFailed, null];
        yield '422 "Invalid Server or Account."' => [422, ['ErrorCode' => 422, 'Message' => 'Invalid server'], [], ProviderCode::AuthFailed, null];
        yield '402 "Invalid JSON."' => [422, ['ErrorCode' => 402, 'Message' => 'Bad JSON'], [], ProviderCode::InvalidRequest, null];
        yield '1101 template not found' => [422, ['ErrorCode' => 1101, 'Message' => 'No template'], [], ProviderCode::InvalidRequest, null];
        yield '1480 "not authorized to send emails from your current IP address"' => [422, ['ErrorCode' => 1480, 'Message' => 'IP'], [], ProviderCode::AuthFailed, null];
        yield '422 InvalidEmailRequest' => [422, ['ErrorCode' => 1003, 'Message' => 'Invalid'], [], ProviderCode::InvalidRequest, null];
        yield '429 retry-after' => [429, ['Message' => 'rate'], ['Retry-After' => '60'], ProviderCode::RateLimited, '60'];
        yield '500' => [500, ['Message' => 'oops'], [], ProviderCode::ProviderUnavailable, null];
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
                /** @var array<string,mixed> $cause */
                $cause = $err->cause;
                $this->assertSame($expectedRetryAfter, $cause['retryAfter'] ?? null);
            }
        }
    }

    /**
     * POST /email fields: MessageStream, Metadata ("Custom metadata key/value
     * pairs."), TrackOpens (boolean), TrackLinks ("None HtmlAndText HtmlOnly
     * TextOnly").
     */
    public function testNarrowedFieldsMapToPostmarkWireNames(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new PostmarkEmailConnector(
            new PostmarkConfig(serverToken: 'test-token', from: 'sender@example.com', messageStream: 'outbound'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $connector->send(new \Thinwrap\Notifications\Providers\Postmark\PostmarkNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            messageStream: 'broadcast',
            metadata: ['order' => '42'],
            trackOpens: true,
            trackLinks: 'HtmlOnly',
        ));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://api.postmarkapp.com/email', (string) $request->getUri());
        /** @var array<string,mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('broadcast', $body['MessageStream']);
        $this->assertSame(['order' => '42'], $body['Metadata']);
        $this->assertTrue($body['TrackOpens']);
        $this->assertSame('HtmlOnly', $body['TrackLinks']);
        $this->assertSame('Test', $body['Subject']);
    }

    /**
     * templates-api: POST /email/withTemplate takes TemplateId ("integer …
     * Required if TemplateAlias is not specified"), TemplateAlias and
     * TemplateModel ("required — The model to be applied to the specified
     * template to generate HtmlBody, TextBody, and Subject").
     */
    public function testTemplateIdRoutesToWithTemplateAndDropsSubjectAndBody(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $connector->send(new \Thinwrap\Notifications\Providers\Postmark\PostmarkNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'unused',
            text: 'unused',
            html: '<p>unused</p>',
            templateId: '12345',
            templateModel: ['name' => 'Ada'],
        ));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://api.postmarkapp.com/email/withTemplate', (string) $request->getUri());
        /** @var array<string,mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(12345, $body['TemplateId']);
        $this->assertSame(['name' => 'Ada'], $body['TemplateModel']);
        $this->assertArrayNotHasKey('Subject', $body);
        $this->assertArrayNotHasKey('HtmlBody', $body);
        $this->assertArrayNotHasKey('TextBody', $body);
        $this->assertSame('recipient@example.com', $body['To']);
    }

    public function testPassthroughTemplateAliasAlsoRoutesAndGetsAnEmptyModel(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $connector->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'unused',
            text: 'unused',
            _passthrough: ['body' => ['TemplateAlias' => 'welcome']],
        ));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://api.postmarkapp.com/email/withTemplate', (string) $request->getUri());
        $this->assertStringContainsString('"TemplateModel":{}', (string) $request->getBody());
    }

    /**
     * Templates API: "TemplateModel | object | required"; postmark-php sends
     * `empty($templateModel) ? (object) [] : $templateModel`.
     *
     * @return iterable<string,array{mixed}>
     */
    public static function emptyTemplateModels(): iterable
    {
        yield 'empty array' => [[]];
        yield 'explicit null' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyTemplateModels')]
    public function testEmptyTemplateModelIsSentAsAnObject(mixed $model): void
    {
        $this->mockClient->addResponse($this->successResponse());

        $this->buildConnector()->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'unused',
            _passthrough: ['body' => ['TemplateId' => 1, 'TemplateModel' => $model]],
        ));

        $this->assertStringContainsString('"TemplateModel":{}', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    /** An empty alias names no template, so the send stays on `/email` with its body. */
    public function testEmptyTemplateAliasDoesNotRoute(): void
    {
        $this->mockClient->addResponse($this->successResponse());

        $this->buildConnector()->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'S',
            text: 'B',
            _passthrough: ['body' => ['TemplateAlias' => '']],
        ));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://api.postmarkapp.com/email', (string) $request->getUri());
        $this->assertStringContainsString('"Subject":"S"', (string) $request->getBody());
    }

    /** Postmark: "TemplateId | integer"; postmark-php sends a non-int id as TemplateAlias. */
    public function testNonNumericTemplateIdIsSentAsTheAlias(): void
    {
        $this->mockClient->addResponse($this->successResponse());

        $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Postmark\PostmarkNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'unused',
            templateId: 'welcome',
        ));

        $raw = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringContainsString('"TemplateAlias":"welcome"', $raw);
        $this->assertStringNotContainsString('"TemplateId"', $raw);
    }

    /**
     * @return iterable<string,array{\Thinwrap\Notifications\Providers\Postmark\PostmarkNarrowedInput}>
     */
    public static function invalidNarrowedInputs(): iterable
    {
        yield 'id and alias together' => [new \Thinwrap\Notifications\Providers\Postmark\PostmarkNarrowedInput(from: 'a@example.com', to: 'b@example.com', subject: 's', templateId: 5, templateAlias: 'welcome')];
        // TrackLinks: "Possible options: None HtmlAndText HtmlOnly TextOnly".
        yield 'trackLinks outside the enum' => [new \Thinwrap\Notifications\Providers\Postmark\PostmarkNarrowedInput(from: 'a@example.com', to: 'b@example.com', subject: 's', text: 't', trackLinks: 'htmlonly')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNarrowedInputs')]
    public function testInvalidNarrowedInputIsRejectedBeforeSending(\Thinwrap\Notifications\Providers\Postmark\PostmarkNarrowedInput $input): void
    {
        try {
            $this->buildConnector()->send($input);
            $this->fail('Expected ConnectorError');
        } catch (\Thinwrap\Notifications\Exception\ConnectorError $e) {
            $this->assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    public function testEmptyMetadataIsSentAsAnObject(): void
    {
        $this->mockClient->addResponse($this->successResponse());

        $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Postmark\PostmarkNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'S',
            text: 'B',
            metadata: [],
        ));

        $this->assertStringContainsString('"Metadata":{}', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    private function basicInput(): EmailSendInput
    {
        return new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
        );
    }

    private function buildConnector(): PostmarkEmailConnector
    {
        return new PostmarkEmailConnector(
            new PostmarkConfig(serverToken: 'test-token', from: 'sender@example.com'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return (new Response(200, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode([
                'MessageID' => 'abc',
                'ErrorCode' => 0,
                'Message' => 'OK',
            ], JSON_THROW_ON_ERROR)));
    }
}
