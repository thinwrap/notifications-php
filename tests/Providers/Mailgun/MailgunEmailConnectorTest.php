<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Mailgun;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Mailgun\MailgunConfig;
use Thinwrap\Notifications\Providers\Mailgun\MailgunEmailConnector;
use Thinwrap\Notifications\Providers\Mailgun\MailgunRegion;

final class MailgunEmailConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testDefaultRegionHitsUsHost(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new MailgunEmailConnector(
            new MailgunConfig(apiKey: 'k', domain: 'mg.example.com'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertStringStartsWith('https://api.mailgun.net/v3/mg.example.com/messages', (string) $request->getUri());
    }

    public function testEuRegionHitsEuHost(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new MailgunEmailConnector(
            new MailgunConfig(apiKey: 'k', domain: 'mg.example.com', region: MailgunRegion::Eu),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertStringStartsWith('https://api.eu.mailgun.net/v3/mg.example.com/messages', (string) $request->getUri());
    }

    public function testBasicAuthHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Basic ' . base64_encode('api:test-key'), $request->getHeaderLine('Authorization'));
    }

    public function testFormEncodedBodyShape(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            replyTo: 'replies@example.com',
            text: 'Body',
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $bodyStr = (string) $request->getBody();
        parse_str($bodyStr, $parsed);
        $this->assertSame('sender@example.com', $parsed['from']);
        $this->assertSame('recipient@example.com', $parsed['to']);
        $this->assertSame('Test', $parsed['subject']);
        $this->assertSame('replies@example.com', $parsed['h:Reply-To']);
    }

    public function testMultiValueTags(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            tags: ['a', 'b', 'c', 'd'],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        $bodyStr = (string) $request->getBody();
        $this->assertSame(3, substr_count($bodyStr, 'o%3Atag='));
        $this->assertStringNotContainsString('o%3Atag=d', $bodyStr);
    }

    public function testAttachmentsBuildMultipartBody(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $attachment = new EmailAttachment(
            filename: 'invoice.pdf',
            content: 'PDF-BYTES',
            contentType: 'application/pdf',
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
        $this->assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        $body = (string) $request->getBody();

        // Scalar form fields are carried as form-data parts.
        $this->assertStringContainsString('name="from"', $body);
        $this->assertStringContainsString('sender@example.com', $body);
        $this->assertStringContainsString('name="subject"', $body);
        $this->assertStringContainsString('name="text"', $body);

        // Attachment part: name="attachment", filename, content type, bytes.
        $this->assertStringContainsString('name="attachment"; filename="invoice.pdf"', $body);
        $this->assertStringContainsString('Content-Type: application/pdf', $body);
        $this->assertStringContainsString('PDF-BYTES', $body);
    }

    /**
     * Mailgun send-inline-image sample: `-F inline=@email.jpg` with
     * `<img src="cid:email.jpg">` — Mailgun derives the Content-ID from the
     * inline part's filename.
     */
    public function testAttachmentWithContentIdUsesInlineField(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $attachment = new EmailAttachment(
            filename: 'logo.png',
            content: 'PNG-BYTES',
            contentType: 'image/png',
            contentId: 'logo123',
        );
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            attachments: [$attachment],
        );
        $connector->send($input);

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringContainsString('name="inline"; filename="logo123"', $body);
        $this->assertStringNotContainsString('name="attachment"', $body);
    }

    public function testAttachmentWithoutContentTypeDefaultsToOctetStream(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $attachment = new EmailAttachment(filename: 'data.bin', content: 'RAW');
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            attachments: [$attachment],
        );
        $connector->send($input);

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringContainsString('name="attachment"; filename="data.bin"', $body);
        $this->assertStringContainsString('Content-Type: application/octet-stream', $body);
    }

    public function testAttachmentContentResolvedFromStreamResource(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, 'STREAMED-BYTES');
        rewind($stream);

        $attachment = new EmailAttachment(
            filename: 'doc.txt',
            content: $stream,
            contentType: 'text/plain',
        );
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            attachments: [$attachment],
        );
        $connector->send($input);

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringContainsString('STREAMED-BYTES', $body);
        fclose($stream);
    }

    public function testTagsEmittedInMultipartPath(): void
    {
        // o:tag must be present in the multipart (attachments) path too.
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $attachment = new EmailAttachment(filename: 'f.txt', content: 'x', contentType: 'text/plain');
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            attachments: [$attachment],
            tags: ['a', 'b', 'c', 'd'],
        );
        $connector->send($input);

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        // Capped at 3 — fourth tag dropped (graceful degradation).
        $this->assertSame(3, substr_count($body, 'name="o:tag"'));
        $this->assertStringContainsString("name=\"o:tag\"\r\n\r\na\r\n", $body);
        $this->assertStringNotContainsString("name=\"o:tag\"\r\n\r\nd\r\n", $body);
    }

    public function testAttachmentFilenameCrlfAndQuoteInjectionSanitized(): void
    {
        // A CR/LF or quote in filename/name must not break out of the
        // Content-Disposition header line (header injection / part smuggling).
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $attachment = new EmailAttachment(
            filename: "evil\r\nContent-Disposition: form-data; name=\"smuggled\".txt",
            content: 'X',
            contentType: "text/plain\r\nX-Injected: 1",
        );
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            attachments: [$attachment],
        );
        $connector->send($input);

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        // CRLF stripped: no injected header line survives (neither the smuggled
        // disposition nor the injected Content-Type continuation appears as its
        // own CRLF-delimited line).
        $this->assertStringNotContainsString("\r\nContent-Disposition: form-data; name=\"smuggled\"", $body);
        $this->assertStringNotContainsString("\r\nX-Injected: 1", $body);
        // The injected text is collapsed inline onto the sanitized Content-Type.
        $this->assertStringContainsString('Content-Type: text/plainX-Injected: 1', $body);
        // The embedded quote is backslash-escaped so it cannot close the param.
        $this->assertStringContainsString('name=\\"smuggled\\"', $body);
        // The legitimate attachment disposition is still well-formed.
        $this->assertStringContainsString('name="attachment"; filename="', $body);
    }

    /**
     * Mailgun messages API: `t:variables` is "A valid JSON-encoded dictionary",
     * and repeatable fields are sent once per value.
     */
    public function testPassthroughMapIsJsonAndListIsRepeatedOnTheFormPath(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $connector->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            _passthrough: ['body' => ['t:variables' => ['name' => 'Ada', 'n' => 1], 'o:tag' => ['a', 'b']]],
        ));

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringContainsString('t%3Avariables=' . rawurlencode('{"name":"Ada","n":1}'), $body);
        $this->assertSame(2, substr_count($body, 'o%3Atag='));
    }

    public function testPassthroughMapIsJsonOnTheMultipartPath(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $connector->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            attachments: [new EmailAttachment(filename: 'a.txt', content: 'x')],
            _passthrough: ['body' => ['t:variables' => ['name' => 'Ada']]],
        ));

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringContainsString("name=\"t:variables\"\r\n\r\n{\"name\":\"Ada\"}\r\n", $body);
    }

    /** Header names are case-insensitive, so a caller `reply-to` must not produce a second Reply-To. */
    public function testReplyToIsNotDuplicatedByACaseVariantHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $connector->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            replyTo: 'typed@example.com',
            text: 'Body',
            headers: ['reply-to' => 'header@example.com', 'X-Ref' => '1'],
        ));

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertSame(1, preg_match_all('/h%3Areply-to=/i', $body));
        $this->assertStringContainsString('h%3AReply-To=' . rawurlencode('typed@example.com'), $body);
        $this->assertStringContainsString('h%3AX-Ref=1', $body);
    }

    /** Mailgun `from`: "Can include a friendly name using the format "Friendly Name <email@domain.com>"". */
    public function testSenderNameWithSpecialsIsQuoted(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new MailgunEmailConnector(
            new MailgunConfig(apiKey: 'test-key', domain: 'mg.example.com', senderName: 'Acme, Inc.'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $connector->send($this->basicInput());

        parse_str((string) $this->mockClient->getRequests()[0]->getBody(), $parsed);
        $this->assertSame('"Acme, Inc." <sender@example.com>', $parsed['from']);
    }

    /**
     * Mailgun messages API: o:tracking / o:tracking-clicks / o:tracking-opens /
     * o:dkim take yes|no; o:testmode and t:text document only "yes";
     * o:deliverytime is RFC-2822; t:variables is JSON; v:<name> per variable.
     */
    public function testNarrowedFieldsMapToMailgunFormFields(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $connector->send(new \Thinwrap\Notifications\Providers\Mailgun\MailgunNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            oTracking: true,
            oTrackingClicks: false,
            oTrackingOpens: true,
            oDeliverytime: 'Fri, 14 Oct 2011 12:00:00 +0000',
            oTestmode: true,
            oDkim: false,
            vCustomVars: ['order' => '42'],
            template: 'welcome',
            tVariables: ['name' => 'Ada'],
            tVersion: 'v2',
            tText: 'yes',
        ));

        // parse_str() would mangle the `o:`-style keys, so decode pairs by hand.
        $parsed = [];
        foreach (explode('&', (string) $this->mockClient->getRequests()[0]->getBody()) as $pair) {
            [$k, $v] = explode('=', $pair, 2);
            $parsed[str_replace(':', '__', rawurldecode($k))] = rawurldecode($v);
        }
        $this->assertSame('yes', $parsed['o__tracking']);
        $this->assertSame('no', $parsed['o__tracking-clicks']);
        $this->assertSame('yes', $parsed['o__tracking-opens']);
        $this->assertSame('Fri, 14 Oct 2011 12:00:00 +0000', $parsed['o__deliverytime']);
        $this->assertSame('yes', $parsed['o__testmode']);
        $this->assertSame('no', $parsed['o__dkim']);
        $this->assertSame('42', $parsed['v__order']);
        $this->assertSame('welcome', $parsed['template']);
        $this->assertSame('{"name":"Ada"}', $parsed['t__variables']);
        $this->assertSame('v2', $parsed['t__version']);
        $this->assertSame('yes', $parsed['t__text']);
    }

    /** `t:variables`: "A valid JSON-encoded dictionary" — an empty one is `{}`. */
    public function testEmptyTemplateVariablesAreAnEmptyJsonObject(): void
    {
        $this->mockClient->addResponse($this->successResponse());

        $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Mailgun\MailgunNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'S',
            template: 'welcome',
            tVariables: [],
        ));

        $this->assertStringContainsString('t%3Avariables=' . rawurlencode('{}'), (string) $this->mockClient->getRequests()[0]->getBody());
    }

    /** Mailgun: a sent subject "will override [the template's] if provided". */
    public function testEmptySubjectIsOmittedOnATemplateSend(): void
    {
        $this->mockClient->addResponse($this->successResponse());

        $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Mailgun\MailgunNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: '',
            template: 'welcome',
        ));

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringNotContainsString('subject=', $body);
        $this->assertStringContainsString('template=welcome', $body);
    }

    /** Mailgun o:tracking / o:tracking-clicks: "yes, no, true, false, htmlonly". */
    public function testTrackingHtmlonlyAndStructuredCustomVariables(): void
    {
        $this->mockClient->addResponse($this->successResponse());

        $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Mailgun\MailgunNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'S',
            text: 'B',
            oTrackingClicks: 'htmlonly',
            vCustomVars: ['meta' => ['a' => 1]],
        ));

        $body = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringContainsString('o%3Atracking-clicks=htmlonly', $body);
        $this->assertStringContainsString('v%3Ameta=' . rawurlencode('{"a":1}'), $body);
    }

    public function testUnknownTrackingStringIsRejected(): void
    {
        try {
            $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Mailgun\MailgunNarrowedInput(
                from: 'sender@example.com',
                to: 'recipient@example.com',
                subject: 'S',
                text: 'B',
                oTracking: 'sometimes',
            ));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $e) {
            $this->assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
    }

    public function testTestmodeFalseIsOmittedBecauseMailgunOnlyDocumentsYes(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildConnector();

        $connector->send(new \Thinwrap\Notifications\Providers\Mailgun\MailgunNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            oTestmode: false,
        ));

        $this->assertStringNotContainsString('testmode', (string) $this->mockClient->getRequests()[0]->getBody());
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
                'body' => ['v:campaign' => 'spring'],
                'headers' => ['X-Idempotency-Key' => 'idem-1'],
            ],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        $bodyStr = (string) $request->getBody();
        $this->assertStringContainsString('v%3Acampaign=spring', $bodyStr);
        $this->assertSame('idem-1', $request->getHeaderLine('X-Idempotency-Key'));
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['message' => 'auth'], [], ProviderCode::AuthFailed, null];
        yield '402' => [402, ['message' => 'payment'], [], ProviderCode::AuthFailed, null];
        // API overview: 403 "Forbidden — Valid credentials but access denied."
        yield '403' => [403, ['message' => 'Domain is not allowed to send'], [], ProviderCode::AuthFailed, null];
        yield '400 invalid email' => [400, ['message' => "'foo' is not a valid email"], [], ProviderCode::InvalidRecipient, null];
        yield '400 other' => [400, ['message' => 'missing subject'], [], ProviderCode::InvalidRequest, null];
        yield '404' => [404, ['message' => 'unknown domain'], [], ProviderCode::InvalidRequest, null];
        yield '429 retry-after' => [429, ['message' => 'rate'], ['Retry-After' => '60'], ProviderCode::RateLimited, '60'];
        yield '500' => [500, ['message' => 'oops'], [], ProviderCode::ProviderUnavailable, null];
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

    private function basicInput(): EmailSendInput
    {
        return new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
        );
    }

    private function buildConnector(): MailgunEmailConnector
    {
        return new MailgunEmailConnector(
            new MailgunConfig(apiKey: 'test-key', domain: 'mg.example.com'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return (new Response(200, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode(['id' => '<msg@mg.example.com>', 'message' => 'Queued. Thank you.'], JSON_THROW_ON_ERROR)));
    }
}
