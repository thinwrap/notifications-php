<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Ses;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Ses\SesConfig;
use Thinwrap\Notifications\Providers\Ses\SesEmailConnector;

final class SesEmailConnectorTest extends TestCase
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
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode(['MessageId' => '0100018f-abc-def'], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Sent, $result->status);
        $this->assertSame('0100018f-abc-def', $result->providerMessageId);
        $this->assertSame(['MessageId' => '0100018f-abc-def'], $result->raw);
    }

    public function testEndpointUsesRegionHost(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector(region: 'eu-west-1');

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails', (string) $request->getUri());
    }

    public function testSignatureDeterminism(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->mockClient->addResponse($this->successResponse());

        $connector = $this->buildConnectorWithFixedTimestamp('20260514T123456Z');

        $connector->send($this->basicInput());
        $connector->send($this->basicInput());

        $requests = $this->mockClient->getRequests();
        $auth0 = $requests[0]->getHeaderLine('Authorization');
        $auth1 = $requests[1]->getHeaderLine('Authorization');
        $this->assertNotSame('', $auth0);
        $this->assertSame($auth0, $auth1, 'Identical inputs at identical timestamps must produce byte-identical signatures.');
    }

    public function testSignatureHeadersPresent(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector(region: 'us-east-1');

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $authorization = $request->getHeaderLine('Authorization');
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=', $authorization);
        $this->assertNotSame('', $request->getHeaderLine('X-Amz-Date'));
        $this->assertSame('email.us-east-1.amazonaws.com', $request->getHeaderLine('Host'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        // SignedHeaders must include the lowercased canonical set.
        $this->assertMatchesRegularExpression(
            '/SignedHeaders=[a-z0-9;-]*content-type[a-z0-9;-]*host[a-z0-9;-]*x-amz-date/',
            $authorization,
        );
    }

    public function testSessionTokenAddedAsHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new SesEmailConnector(
            new SesConfig(region: 'us-east-1', accessKeyId: 'AKIA', secretAccessKey: 'secret', sessionToken: 'STS-TOKEN'),
            ...$this->httpDeps(),
        );

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('STS-TOKEN', $request->getHeaderLine('X-Amz-Security-Token'));
        $this->assertStringContainsString('x-amz-security-token', $request->getHeaderLine('Authorization'));
    }

    public function testUnsignableHeadersSentButNotSigned(): void
    {
        // Per the AWS signing guide ("Do not include hop-by-hop headers ...");
        // signing these invites SignatureDoesNotMatch when a proxy or the HTTP
        // client rewrites them after signing. Mirrors the TS sibling spec.
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Unsignable',
            text: 'Body',
            _passthrough: [
                'headers' => ['User-Agent' => 'consumer-ua/1.0', 'X-Custom' => 'v'],
            ],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        $auth = $request->getHeaderLine('Authorization');
        $this->assertSame('consumer-ua/1.0', $request->getHeaderLine('User-Agent')); // on the wire
        $this->assertStringNotContainsString('user-agent', $auth); // not signed
        $this->assertStringContainsString('x-custom', $auth); // normal passthrough headers still are
    }

    /**
     * sesv2 `Attachment`: "RawContent … needs to be base64-encoded if you are
     * accessing Amazon SES directly through the HTTPS interface"; `ContentId`
     * is "used for referencing attachments with INLINE disposition in HTML
     * content"; ContentDisposition is `ATTACHMENT` or `INLINE`.
     */
    public function testAttachmentsGoThroughSimpleContentAttachments(): void
    {
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"MessageId":"att-1"}'))
        );
        $connector = $this->buildConnector();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            html: '<p><img src="cid:logo"></p>',
            attachments: [
                new EmailAttachment(filename: 'file.txt', content: 'hello', contentType: 'text/plain'),
                new EmailAttachment(filename: 'logo.png', content: 'PNG', contentType: 'image/png', contentId: 'logo'),
                new EmailAttachment(filename: 'blob.bin', content: 'x'),
            ],
        );

        $result = $connector->send($input);

        $this->assertSame('att-1', $result->providerMessageId);
        $decoded = $this->sentBody();
        $this->assertArrayNotHasKey('Raw', $decoded['Content']);
        $this->assertSame(
            [
                ['FileName' => 'file.txt', 'RawContent' => base64_encode('hello'), 'ContentType' => 'text/plain', 'ContentDisposition' => 'ATTACHMENT'],
                ['FileName' => 'logo.png', 'RawContent' => base64_encode('PNG'), 'ContentType' => 'image/png', 'ContentId' => 'logo', 'ContentDisposition' => 'INLINE'],
                ['FileName' => 'blob.bin', 'RawContent' => base64_encode('x'), 'ContentDisposition' => 'ATTACHMENT'],
            ],
            $decoded['Content']['Simple']['Attachments'],
        );
        $this->assertSame(['Data' => 'Test', 'Charset' => 'UTF-8'], $decoded['Content']['Simple']['Subject']);
    }

    /** sesv2 `Message.Headers`: "The list of message headers that will be added to the email message." */
    public function testHeadersGoThroughSimpleContentHeaders(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $connector->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Réservation',
            text: 'café ☕',
            headers: ['X-Entity-Ref-Id' => 'abc', 'List-Unsubscribe' => '<https://example.com/u>'],
        ));

        $decoded = $this->sentBody();
        $this->assertSame(
            [['Name' => 'X-Entity-Ref-Id', 'Value' => 'abc'], ['Name' => 'List-Unsubscribe', 'Value' => '<https://example.com/u>']],
            $decoded['Content']['Simple']['Headers'],
        );
        $this->assertSame(['Data' => 'café ☕', 'Charset' => 'UTF-8'], $decoded['Content']['Simple']['Body']['Text']);
    }

    /**
     * @return iterable<string,array{array<string,string>}>
     */
    public static function rejectedHeaders(): iterable
    {
        // header-fields.html: "the following headers are disallowed as custom
        // headers: BCC, CC, Content-Disposition, Content-Type, Date, From,
        // Message-ID, MIME-Version, Reply-To, Return-Path, Subject, To"
        yield 'Reply-To is set by SES' => [['reply-to' => 'x@example.com']];
        yield 'Content-Type is set by SES' => [['Content-Type' => 'text/plain']];
        yield 'From is set by SES' => [['FROM' => 'x@example.com']];
        // MessageHeaderValue: "Can contain any printable ASCII character."
        yield 'trailing LF in value' => [['X-Note' => "v\n"]];
        yield 'CRLF in value' => [['X-Note' => "a\r\nBcc: x@example.com"]];
        // MessageHeaderName: "any printable ASCII character (33 - 126) except for colon (:)"
        yield 'colon in name' => [['X-A:B' => 'v']];
        // MessageHeaderList: "Can contain no more than 15 headers in one message."
        yield 'more than 15 headers' => [array_combine(
            array_map(static fn(int $i): string => "X-H{$i}", range(1, 16)),
            array_fill(0, 16, 'v'),
        )];
    }

    /**
     * @param array<string,string> $headers
     */
    #[DataProvider('rejectedHeaders')]
    public function testHeadersSesRejectsFailBeforeSending(array $headers): void
    {
        $connector = $this->buildConnector();

        try {
            $connector->send(new EmailSendInput(from: 'sender@example.com', to: 'r@example.com', subject: 'S', text: 'B', headers: $headers));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    /**
     * SES: "If you want to use Unicode characters in the "friendly from" name,
     * you must encode the "friendly from" name using MIME encoded-word syntax";
     * RFC 5322 requires a name with specials to be a quoted-string.
     *
     * @return iterable<string,array{string|null,string,string}>
     */
    public static function senderNames(): iterable
    {
        yield 'plain atoms' => ['Acme Mail', 'sender@example.com', 'Acme Mail <sender@example.com>'];
        yield 'specials are quoted' => ['Acme, Inc.', 'sender@example.com', '"Acme, Inc." <sender@example.com>'];
        yield 'quotes are escaped' => ['Joe "JJ" Doe', 'sender@example.com', '"Joe \\"JJ\\" Doe" <sender@example.com>'];
        yield 'non-ASCII is an encoded-word' => ['Café Señor', 'sender@example.com', '=?UTF-8?B?' . base64_encode('Café Señor') . '?= <sender@example.com>'];
        yield 'no name' => [null, 'sender@example.com', 'sender@example.com'];
        yield 'embedded name is not double-wrapped' => ['Config Name', 'Per Message <sender@example.com>', 'Per Message <sender@example.com>'];
    }

    #[DataProvider('senderNames')]
    public function testFromEmailAddressFormatsTheDisplayName(?string $senderName, string $from, string $expected): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new SesEmailConnector(
            new SesConfig(region: 'us-east-1', accessKeyId: 'AKIATEST', secretAccessKey: 'secretvalue', senderName: $senderName),
            ...$this->httpDeps(),
        );

        $connector->send(new EmailSendInput(from: $from, to: 'recipient@example.com', subject: 'S', text: 'B'));

        $this->assertSame($expected, $this->sentBody()['FromEmailAddress']);
    }

    /** RFC 2047 §2: "An 'encoded-word' may not be more than 75 characters long". */
    public function testLongNonAsciiSenderNameSplitsIntoShortEncodedWords(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $name = str_repeat('Ünïcødé ', 12);
        $connector = new SesEmailConnector(
            new SesConfig(region: 'us-east-1', accessKeyId: 'AKIATEST', secretAccessKey: 'secretvalue', senderName: $name),
            ...$this->httpDeps(),
        );

        $connector->send($this->basicInput());

        $from = $this->sentBody()['FromEmailAddress'];
        $this->assertStringEndsWith(' <sender@example.com>', $from);
        $words = explode(' ', substr($from, 0, -strlen(' <sender@example.com>')));
        $this->assertGreaterThan(1, count($words));
        $decoded = '';
        foreach ($words as $word) {
            $this->assertLessThanOrEqual(75, strlen($word));
            $this->assertSame(1, preg_match('/^=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=$/', $word, $m));
            $chunk = base64_decode($m[1], true);
            $this->assertIsString($chunk);
            $this->assertSame(1, preg_match('//u', $chunk), 'each word must hold whole UTF-8 characters');
            $decoded .= $chunk;
        }
        $this->assertSame(trim($name), $decoded);
    }

    /**
     * MessageHeaderValue allows only printable ASCII, so a non-ASCII value goes
     * as RFC 2047 encoded-words ("An 'encoded-word' may not be more than 75
     * characters long").
     */
    public function testNonAsciiHeaderValueIsSentAsEncodedWords(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $value = str_repeat('Réservation confirmée ', 4);

        $this->buildConnector()->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'S',
            text: 'B',
            headers: ['X-Note' => $value, 'X-Plain' => 'ascii'],
        ));

        $headers = $this->sentBody()['Content']['Simple']['Headers'];
        $this->assertSame(['Name' => 'X-Plain', 'Value' => 'ascii'], $headers[1]);
        $words = explode(' ', $headers[0]['Value']);
        $this->assertGreaterThan(1, count($words));
        $decoded = '';
        foreach ($words as $word) {
            $this->assertLessThanOrEqual(75, strlen($word));
            $this->assertSame(1, preg_match('/^=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=$/', $word, $m));
            $decoded .= (string) base64_decode($m[1], true);
        }
        $this->assertSame($value, $decoded);
    }

    /** SES addresses are sent as given; only the display name is formatted. */
    public function testNonAsciiAddressIsSentAsGiven(): void
    {
        $this->mockClient->addResponse($this->successResponse());

        $this->buildConnector()->send(new EmailSendInput(from: 'sender@example.com', to: 'jörg@bücher.example', subject: 'S', text: 'B'));

        $this->assertSame(['jörg@bücher.example'], $this->sentBody()['Destination']['ToAddresses']);
    }

    /** sesv2 `MessageTag`: "It can only contain ASCII letters (a–z, A–Z), numbers (0–9), underscores (_), or dashes (-)." */
    public function testTagOutsideTheSesCharsetIsRejectedBeforeSending(): void
    {
        $connector = $this->buildConnector();

        try {
            $connector->send(new EmailSendInput(from: 'sender@example.com', to: 'r@example.com', subject: 'S', text: 'B', tags: ['welcome email']));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    /** botocore `validate_region_name`: the region becomes part of the signed request's host. */
    public function testRegionThatIsNotAHostLabelIsRejectedBeforeSending(): void
    {
        $connector = $this->buildConnector(region: 'x.evil.test/');

        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    public function testTagsMapToEmailTags(): void
    {
        // a documented design decision: tags lift to EmailTags: [{Name, Value}].
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"MessageId":"tag-1"}'))
        );
        $connector = $this->buildConnector();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            tags: ['campaign', 'welcome'],
        );

        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [['Name' => 'campaign', 'Value' => 'campaign'], ['Name' => 'welcome', 'Value' => 'welcome']],
            $decoded['EmailTags'],
        );
    }

    public function testPassthroughBodyMergedAndSigned(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnectorWithFixedTimestamp('20260514T123456Z');

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            _passthrough: [
                'body' => [
                    'ConfigurationSetName' => 'campaign-A',
                    'EmailTags' => [['Name' => 'tag1', 'Value' => 'v1']],
                ],
            ],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('campaign-A', $decoded['ConfigurationSetName']);
        $this->assertSame([['Name' => 'tag1', 'Value' => 'v1']], $decoded['EmailTags']);
        // Signature must still validate against the merged body — re-running
        // signRequest with identical inputs should produce identical Authorization.
        $this->mockClient->addResponse($this->successResponse());
        $secondConnector = $this->buildConnectorWithFixedTimestamp('20260514T123456Z');
        $secondConnector->send($input);
        $second = $this->mockClient->getRequests()[1];
        $this->assertSame($request->getHeaderLine('Authorization'), $second->getHeaderLine('Authorization'));
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        // SendEmail errors: MessageRejected "The message can't be sent because
        // it contains invalid content."
        yield 'MessageRejected => InvalidRequest' => [
            400,
            ['__type' => 'MessageRejected', 'message' => 'Email rejected'],
            [],
            ProviderCode::InvalidRequest,
            null,
        ];
        // SendingPausedException: "sending for your account is currently paused".
        yield 'SendingPausedException => AuthFailed' => [
            400,
            ['message' => 'Sending paused'],
            ['x-amzn-ErrorType' => 'SendingPausedException:http://internal.amazon.com/coral/com.amazonaws.sesv2/'],
            ProviderCode::AuthFailed,
            null,
        ];
        // restJson1: the x-amzn-ErrorType header wins over the body (botocore
        // RestJSONParser._inject_error_code checks the header first).
        yield 'x-amzn-ErrorType header wins over body' => [
            400,
            ['__type' => 'BadRequestException'],
            ['x-amzn-ErrorType' => 'AccountSuspendedException'],
            ProviderCode::AuthFailed,
            null,
        ];
        yield 'lowercase body code, namespaced' => [
            400,
            ['code' => 'com.amazonaws.sesv2#SendingPausedException'],
            [],
            ProviderCode::AuthFailed,
            null,
        ];
        yield 'MailFromDomainNotVerified => InvalidRequest' => [
            400,
            ['__type' => 'MailFromDomainNotVerified'],
            [],
            ProviderCode::InvalidRequest,
            null,
        ];
        yield 'SignatureDoesNotMatch => AuthFailed' => [
            403,
            ['__type' => 'SignatureDoesNotMatch'],
            [],
            ProviderCode::AuthFailed,
            null,
        ];
        yield '429 Throttling with Retry-After => RateLimited' => [
            429,
            ['__type' => 'Throttling', 'message' => 'Rate exceeded'],
            ['Retry-After' => '30'],
            ProviderCode::RateLimited,
            '30',
        ];
        yield 'InternalFailure => ProviderUnavailable' => [
            500,
            ['__type' => 'InternalFailure'],
            [],
            ProviderCode::ProviderUnavailable,
            null,
        ];
        yield 'Unknown 4xx code => InvalidRequest' => [
            418,
            ['__type' => 'WeirdNewThing'],
            [],
            ProviderCode::InvalidRequest,
            null,
        ];
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
     * @return array<string,mixed>
     */
    private function sentBody(): array
    {
        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function basicInput(): EmailSendInput
    {
        return new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            html: '<p>Body</p>',
        );
    }

    private function buildConnector(string $region = 'us-east-1'): SesEmailConnector
    {
        return new SesEmailConnector(
            new SesConfig(region: $region, accessKeyId: 'AKIATEST', secretAccessKey: 'secretvalue'),
            ...$this->httpDeps(),
        );
    }

    private function buildConnectorWithFixedTimestamp(string $timestamp): SesEmailConnector
    {
        return new class (new SesConfig(region: 'us-east-1', accessKeyId: 'AKIATEST', secretAccessKey: 'secretvalue'), $this->mockClient, $this->factory, $this->factory, $timestamp) extends SesEmailConnector {
            public function __construct(
                SesConfig $config,
                ClientInterface $client,
                RequestFactoryInterface $requestFactory,
                StreamFactoryInterface $streamFactory,
                private readonly string $fixedTimestamp,
            ) {
                parent::__construct($config, $client, $requestFactory, $streamFactory);
            }

            protected function getTimestamp(): string
            {
                return $this->fixedTimestamp;
            }
        };
    }

    private function successResponse(): Response
    {
        return (new Response(200, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode(['MessageId' => 'abc'], JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array{0:ClientInterface,1:RequestFactoryInterface,2:StreamFactoryInterface}
     */
    private function httpDeps(): array
    {
        return [$this->mockClient, $this->factory, $this->factory];
    }
}
