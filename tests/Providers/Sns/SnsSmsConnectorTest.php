<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Sns;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Sns\SnsConfig;
use Thinwrap\Notifications\Providers\Sns\SnsNarrowedInput;
use Thinwrap\Notifications\Providers\Sns\SnsSmsConnector;

final class SnsSmsConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathParsesMessageId(): void
    {
        $xml = '<?xml version="1.0"?><PublishResponse><PublishResult><MessageId>abc-123</MessageId></PublishResult></PublishResponse>';
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'text/xml']))->withBody($this->factory->createStream($xml))
        );
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('abc-123', $result->providerMessageId);
        $this->assertSame($xml, $result->raw);
    }

    public function testEndpointAndContentType(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://sns.us-east-1.amazonaws.com/', (string) $request->getUri());
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
    }

    public function testSignatureHeadersPresent(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $auth = $request->getHeaderLine('Authorization');
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=', $auth);
        $this->assertNotSame('', $request->getHeaderLine('X-Amz-Date'));
        $this->assertSame('sns.us-east-1.amazonaws.com', $request->getHeaderLine('Host'));
        // X-Amz-Content-Sha256 is signed — parity with SES and the TS siblings.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $request->getHeaderLine('X-Amz-Content-Sha256'));
        $this->assertMatchesRegularExpression(
            '/SignedHeaders=[a-z0-9;-]*content-type[a-z0-9;-]*host[a-z0-9;-]*x-amz-content-sha256[a-z0-9;-]*x-amz-date/',
            $auth,
        );
    }

    public function testUnsignableHeadersSentButNotSigned(): void
    {
        // Per the AWS signing guide ("Do not include hop-by-hop headers ...");
        // signing these invites SignatureDoesNotMatch when a proxy or the HTTP
        // client rewrites them after signing. Mirrors the TS sibling spec.
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $input = new SmsSendInput(
            to: '+15551234567',
            body: 'hi',
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

    public function testSignatureDeterminism(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnectorFixed('20260514T123456Z');
        $connector->send($this->basicInput());
        $connector->send($this->basicInput());

        $req = $this->mockClient->getRequests();
        $this->assertSame($req[0]->getHeaderLine('Authorization'), $req[1]->getHeaderLine('Authorization'));
    }

    public function testCrossLanguageDeterministicSignature(): void
    {
        // Pinned vector shared with the TS sibling (`sns.connector.spec.ts`,
        // "matches the cross-language parity vector pinned in the PHP
        // sibling") — identical credentials, timestamp, body, and passthrough
        // query produce this exact Authorization in both languages (verified
        // byte-for-byte 2026-06-04; the TS signer is additionally
        // cross-verified against `aws4@1.13.x` and `aws4fetch@1.0.20`).
        // Body deliberately space-free: PHP serializes form bodies with `%20`
        // (rawurlencode), TS with `+` (URLSearchParams), so a body with
        // spaces would diverge at the wire level, not the signer level.
        $this->mockClient->addResponse($this->successResponse());
        $connector = new class (new SnsConfig(region: 'us-east-1', accessKeyId: 'AKIAIOSFODNN7EXAMPLE', secretAccessKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'), $this->mockClient, $this->factory, $this->factory) extends SnsSmsConnector {
            protected function getTimestamp(): string
            {
                return '20260516T000000Z';
            }
        };

        $connector->send(new SmsSendInput(
            to: '+15555550100',
            body: 'Hi',
            _passthrough: ['query' => ['trace' => 'on']],
        ));

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://sns.us-east-1.amazonaws.com/?trace=on', (string) $request->getUri());
        $this->assertSame(
            'Action=Publish&Version=2010-03-31&Message=Hi&PhoneNumber=%2B15555550100',
            (string) $request->getBody(),
        );
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20260516/us-east-1/sns/aws4_request, '
            . 'SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, '
            . 'Signature=ef9af20cba7c92a45fbabc0ab557ad08b1e91032547b79de9c37f0d8b93a6a15',
            $request->getHeaderLine('Authorization'),
        );
    }

    public function testSessionTokenHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new SnsSmsConnector(
            new SnsConfig(region: 'us-east-1', accessKeyId: 'AKIA', secretAccessKey: 's', sessionToken: 'sts-token'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('sts-token', $request->getHeaderLine('X-Amz-Security-Token'));
        $this->assertStringContainsString('x-amz-security-token', $request->getHeaderLine('Authorization'));
    }

    /**
     * botocore `validate_region_name` shape: the region is interpolated into
     * `sns.{region}.amazonaws.com`, the host that receives the SigV4 signature
     * and any session token.
     */
    public function testRegionThatIsNotAHostLabelIsRejectedBeforeSending(): void
    {
        foreach (['x.evil.test/', "us-east-1\n"] as $region) {
            $connector = new SnsSmsConnector(
                new SnsConfig(region: $region, accessKeyId: 'AKIA', secretAccessKey: 's', sessionToken: 'sts-token'),
                $this->mockClient,
                $this->factory,
                $this->factory,
            );

            try {
                $connector->send($this->basicInput());
                $this->fail('Expected ConnectorError');
            } catch (ConnectorError $e) {
                $this->assertSame(ProviderCode::InvalidRequest, $e->providerCode);
                $this->assertStringContainsString('region', (string) $e->providerMessage);
            }
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    public function testActionAndPhoneNumberInBody(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $body = (string) $request->getBody();
        $this->assertStringContainsString('Action=Publish', $body);
        $this->assertStringContainsString('Version=2010-03-31', $body);
        $this->assertStringContainsString('PhoneNumber=', $body);
    }

    public function testTopicArnAlternative(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new SnsNarrowedInput(to: '', body: 'hi', topicArn: 'arn:aws:sns:us-east-1:123:topic');
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        $body = (string) $request->getBody();
        $this->assertStringContainsString('TopicArn=', $body);
        $this->assertStringNotContainsString('PhoneNumber=', $body);
    }

    public function testSmsTypeFlattenedToMessageAttributes(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();
        $input = new SnsNarrowedInput(
            to: '+15551234567',
            body: 'hi',
            smsType: 'Transactional',
            senderId: 'ACME',
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        $body = (string) $request->getBody();
        $this->assertStringContainsString('MessageAttributes.entry.1.Name=AWS.SNS.SMS.SMSType', urldecode($body));
        $this->assertStringContainsString('MessageAttributes.entry.1.Value.StringValue=Transactional', urldecode($body));
        $this->assertStringContainsString('MessageAttributes.entry.2.Name=AWS.SNS.SMS.SenderID', urldecode($body));
    }

    public function testXmlErrorMappedToInvalidParameter(): void
    {
        $xml = '<ErrorResponse><Error><Code>InvalidParameter</Code><Message>bad message</Message></Error></ErrorResponse>';
        $this->mockClient->addResponse(
            (new Response(400, ['Content-Type' => 'text/xml']))->withBody($this->factory->createStream($xml))
        );
        $connector = $this->buildConnector();

        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            $this->assertSame('bad message', $err->providerMessage);
        }
    }

    public function testThrottlingMaps429(): void
    {
        $xml = '<ErrorResponse><Error><Code>Throttling</Code><Message>too many</Message></Error></ErrorResponse>';
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'text/xml', 'Retry-After' => '7']))->withBody($this->factory->createStream($xml))
        );
        $connector = $this->buildConnector();
        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 7 seconds', (string) $err->providerMessage);
        }
    }

    public function testTransportFailureDoesNotLeakRawMessage(): void
    {
        // The raw PSR-18 client-exception message can embed the signed request
        // URL (SigV4 query params are credential material). The hardened base
        // `dispatch()` must surface only a generic message + the exception class.
        $secret = 'https://sns.us-east-1.amazonaws.com/?X-Amz-Signature=deadbeefSECRET';
        $this->mockClient->addException(
            new class ($secret) extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {}
        );
        $connector = $this->buildConnector();

        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::ProviderUnavailable, $err->providerCode);
            $this->assertSame('Upstream transport error', $err->providerMessage);
            $this->assertStringNotContainsString($secret, (string) $err->providerMessage);

            $encodedCause = json_encode($err->cause);
            $this->assertNotFalse($encodedCause);
            $this->assertStringNotContainsString($secret, $encodedCause);
            $this->assertStringNotContainsString('SECRET', $encodedCause);

            /** @var array{raw:array{exception:string}} $cause */
            $cause = $err->cause;
            $this->assertArrayHasKey('raw', $cause);
            $this->assertArrayHasKey('exception', $cause['raw']);
            // The leaky inline path exposed a top-level `message`; it must be gone.
            $this->assertArrayNotHasKey('message', $cause);
        }
    }

    private function basicInput(): SmsSendInput
    {
        return new SmsSendInput(to: '+15551234567', body: 'hi');
    }

    private function buildConnector(): SnsSmsConnector
    {
        return new SnsSmsConnector(
            new SnsConfig(region: 'us-east-1', accessKeyId: 'AKIA', secretAccessKey: 'secret'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function buildConnectorFixed(string $timestamp): SnsSmsConnector
    {
        return new class (new SnsConfig(region: 'us-east-1', accessKeyId: 'AKIA', secretAccessKey: 's'), $this->mockClient, $this->factory, $this->factory, $timestamp) extends SnsSmsConnector {
            public function __construct(
                SnsConfig $config,
                ClientInterface $client,
                RequestFactoryInterface $rf,
                StreamFactoryInterface $sf,
                private readonly string $fixedTimestamp,
            ) {
                parent::__construct($config, $client, $rf, $sf);
            }
            protected function getTimestamp(): string
            {
                return $this->fixedTimestamp;
            }
        };
    }

    private function successResponse(): Response
    {
        $xml = '<PublishResponse><PublishResult><MessageId>m</MessageId></PublishResult></PublishResponse>';
        return (new Response(200, ['Content-Type' => 'text/xml']))->withBody($this->factory->createStream($xml));
    }
}
