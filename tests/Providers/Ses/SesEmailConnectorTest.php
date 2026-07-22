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

    public function testAttachmentsBuildRawMimeMessage(): void
    {
        // a documented design decision: SES attachments are now supported via the MIME
        // builder (Content.Raw), replacing the prior "deferred to v1.1" throw.
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"MessageId":"mime-1"}'))
        );
        $connector = $this->buildConnector();

        $attachment = new EmailAttachment(
            filename: 'file.txt',
            contentType: 'text/plain',
            content: 'hello',
        );
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            html: '<p>Body</p>',
            attachments: [$attachment],
        );

        $result = $connector->send($input);

        $this->assertSame('mime-1', $result->providerMessageId);
        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('Content', $decoded);
        $this->assertArrayHasKey('Raw', $decoded['Content']);
        $rawMime = base64_decode($decoded['Content']['Raw']['Data'], true);
        $this->assertNotFalse($rawMime);
        $this->assertStringContainsString('Content-Type: multipart/mixed', $rawMime);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="file.txt"', $rawMime);
        // base64 of 'hello'
        $this->assertStringContainsString(base64_encode('hello'), $rawMime);
    }

    public function testRawMimeFromHeaderKeepsBareAddressWithNonAsciiSenderName(): void
    {
        // RFC 2047 forbids encoded-words inside an addr-spec: a non-ASCII
        // senderName must encode ONLY the display-name phrase, leaving a
        // parseable bare `<addr@domain>` for SES to route/reply to. An
        // attachment forces the raw-MIME (Content.Raw) path.
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"MessageId":"from-1"}'))
        );
        $connector = new SesEmailConnector(
            new SesConfig(
                region: 'us-east-1',
                accessKeyId: 'AKIATEST',
                secretAccessKey: 'secretvalue',
                senderName: 'Café Señor',
            ),
            ...$this->httpDeps(),
        );

        $attachment = new EmailAttachment(
            filename: 'file.txt',
            contentType: 'text/plain',
            content: 'hello',
        );
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            attachments: [$attachment],
        );

        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $rawMime = base64_decode($decoded['Content']['Raw']['Data'], true);
        $this->assertNotFalse($rawMime);

        // Grab the From header line.
        $this->assertSame(1, preg_match('/^From: (.*)$/m', $rawMime, $m));
        $fromValue = $m[1];

        // The routable address must remain a bare, parseable `<addr@domain>` —
        // NOT swallowed inside a single encoded-word.
        $this->assertStringContainsString('<sender@example.com>', $fromValue);
        $this->assertStringNotContainsString('<sender@example.com>?=', $fromValue);
        // The non-ASCII display-name is RFC 2047 base64 encoded on its own.
        $this->assertStringContainsString('=?UTF-8?B?' . base64_encode('Café Señor') . '?=', $fromValue);
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
        yield 'MessageRejected => InvalidRecipient' => [
            400,
            ['__type' => 'MessageRejected', 'message' => 'Email rejected'],
            [],
            ProviderCode::InvalidRecipient,
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

    public function testLargeAttachmentBase64IsLineWrapped(): void
    {
        // An unwrapped base64 blob >~748 bytes would exceed RFC 5322's
        // 998-octet line cap; the MIME builder must fold it into ≤76-char lines.
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"MessageId":"wrap-1"}'))
        );
        $connector = $this->buildConnector();

        $content = str_repeat('A', 1000);
        $attachment = new EmailAttachment(
            filename: 'big.bin',
            contentType: 'application/octet-stream',
            content: $content,
        );
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Big',
            text: 'Body',
            attachments: [$attachment],
        );

        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $rawMime = base64_decode($decoded['Content']['Raw']['Data'], true);
        $this->assertNotFalse($rawMime);

        // The flat, unwrapped base64 must NOT appear verbatim — it must be folded.
        $flat = base64_encode($content);
        $this->assertStringNotContainsString($flat, $rawMime);

        // Scan the actual MIME lines: every base64 payload line ≤76 chars, >1 line.
        $base64LineCount = 0;
        $maxBase64LineLength = 0;
        foreach (explode("\r\n", $rawMime) as $line) {
            if ($line !== '' && strlen($line) >= 40 && preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $line) === 1) {
                $base64LineCount++;
                $maxBase64LineLength = max($maxBase64LineLength, strlen($line));
            }
        }
        $this->assertGreaterThan(1, $base64LineCount, 'base64 payload must span multiple folded lines');
        $this->assertLessThanOrEqual(76, $maxBase64LineLength);
    }

    public function testNonAsciiBodyDeclaresBase64Not8bitOr7bit(): void
    {
        // A custom header forces the raw-MIME path; the UTF-8 body must be
        // base64-encoded (7-bit-safe), never 8bit/7bit.
        $this->mockClient->addResponse(
            (new Response(200))->withBody($this->factory->createStream('{"MessageId":"utf-1"}'))
        );
        $connector = $this->buildConnector();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Réservation',
            text: 'café ☕',
            html: '<p>café ☕</p>',
            headers: ['X-Entity-Ref-Id' => 'abc'],
        );

        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $rawMime = base64_decode($decoded['Content']['Raw']['Data'], true);
        $this->assertNotFalse($rawMime);

        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $rawMime);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $rawMime);
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $rawMime);
        $this->assertStringNotContainsString('Content-Transfer-Encoding: 8bit', $rawMime);
        $this->assertStringNotContainsString('Content-Transfer-Encoding: 7bit', $rawMime);
        // The UTF-8 body survives intact: the base64-encoded part decodes back.
        $this->assertStringContainsString(base64_encode('café ☕'), $rawMime);
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
