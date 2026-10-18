<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Mailersend;

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
use Thinwrap\Notifications\Providers\Mailersend\MailersendConfig;
use Thinwrap\Notifications\Providers\Mailersend\MailersendEmailConnector;

final class MailersendEmailConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPathReturnsMessageIdFromHeader(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'msg-1']));
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertSame('msg-1', $result->providerMessageId);
        $this->assertSame(EmailStatus::Queued, $result->status);
        $this->assertTrue($result->success);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('XMLHttpRequest', $request->getHeaderLine('X-Requested-With'));
    }

    public function testEmptyMessageIdHeaderCoercesToNull(): void
    {
        $this->mockClient->addResponse(new Response(202));
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertNull($result->providerMessageId);
    }

    public function testFromObjectShapeWithSenderName(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = new MailersendEmailConnector(
            new MailersendConfig(apiToken: 't', senderName: 'Alice'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        $input = new EmailSendInput(
            from: 'alice@x',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['email' => 'alice@x', 'name' => 'Alice'], $decoded['from']);
    }

    public function testFromObjectShapeWithoutSenderName(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'alice@x',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['email' => 'alice@x'], $decoded['from']);
    }

    public function testTagsTruncatedToFive(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            tags: ['a', 'b', 'c', 'd', 'e', 'f'],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(5, $decoded['tags']);
        $this->assertSame('e', $decoded['tags'][4]);
    }

    public function testAttachmentBase64AndContentId(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();
        $attachment = new EmailAttachment(
            filename: 'file.pdf',
            contentType: 'application/pdf',
            content: 'data',
            contentId: 'cid1',
        );
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            attachments: [$attachment],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $att = $decoded['attachments'][0];
        $this->assertSame(base64_encode('data'), $att['content']);
        $this->assertSame('cid1', $att['id']);
        $this->assertSame('inline', $att['disposition']);
        // MailerSend attachments: content, filename, disposition, id — no content-type field.
        $this->assertArrayNotHasKey('content_type', $att);
    }

    /**
     * POST /v1/email: `template_id`, `personalization` [{email, data}],
     * `send_at` (unix timestamp), `precedence_bulk`, and `settings` which "Can
     * only contain the keys: track_clicks, track_opens and track_content".
     */
    public function testNarrowedFieldsMapToMailersendWireNames(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();

        $connector->send(new \Thinwrap\Notifications\Providers\Mailersend\MailersendNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            templateId: 'tpl-1',
            personalization: [['email' => 'recipient@example.com', 'data' => ['name' => 'Ada']]],
            sendAt: 1_790_000_000,
            precedenceBulk: true,
            settings: ['trackClicks' => false, 'trackOpens' => true, 'trackContent' => true],
        ));

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('tpl-1', $body['template_id']);
        $this->assertSame([['email' => 'recipient@example.com', 'data' => ['name' => 'Ada']]], $body['personalization']);
        $this->assertSame(1_790_000_000, $body['send_at']);
        $this->assertTrue($body['precedence_bulk']);
        $this->assertSame(['track_clicks' => false, 'track_opens' => true, 'track_content' => true], $body['settings']);
    }

    /**
     * MailerSend settings "Can only contain the keys: track_clicks, track_opens
     * and track_content and a boolean value of true or false".
     */
    public function testSnakeCaseSettingsKeysAreAccepted(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));

        $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Mailersend\MailersendNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            settings: ['track_clicks' => false],
        ));

        $this->assertStringContainsString('"settings":{"track_clicks":false}', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    /**
     * @return iterable<string,array{array<string,mixed>}>
     */
    public static function badSettings(): iterable
    {
        yield 'non-boolean value' => [['trackClicks' => 'no']];
        yield 'unknown key' => [['track_everything' => true]];
    }

    /**
     * @param array<string,mixed> $settings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSettings')]
    public function testInvalidSettingsAreRejectedBeforeSending(array $settings): void
    {
        try {
            $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Mailersend\MailersendNarrowedInput(
                from: 'sender@example.com',
                to: 'recipient@example.com',
                subject: 'Test',
                text: 'hi',
                settings: $settings,
            ));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $e) {
            $this->assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    /** MailerSend personalization: `[{email, data}]` — an entry without `data` is a request error, not a PHP warning. */
    public function testPersonalizationWithoutDataIsRejectedBeforeSending(): void
    {
        /** @var list<array{email:string,data:array<string,mixed>}> $personalization */
        $personalization = [['email' => 'recipient@example.com']];
        try {
            $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Mailersend\MailersendNarrowedInput(
                from: 'sender@example.com',
                to: 'recipient@example.com',
                subject: 'Test',
                text: 'hi',
                personalization: $personalization,
            ));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $e) {
            $this->assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    /** subject is "Not required if `template_id` is present with default subject set". */
    public function testEmptySubjectIsOmittedOnATemplateSend(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));

        $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Mailersend\MailersendNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: '',
            templateId: 'tpl-1',
            personalization: [['email' => 'recipient@example.com', 'data' => []]],
        ));

        $raw = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringNotContainsString('"subject"', $raw);
        $this->assertStringContainsString('"data":{}', $raw);
    }

    /** MailerSend's current email API has no `variables` (simple personalization was removed). */
    public function testDeprecatedVariablesAreNotSent(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();

        $connector->send(new \Thinwrap\Notifications\Providers\Mailersend\MailersendNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            variables: [['email' => 'recipient@example.com', 'substitutions' => [['var' => 'name', 'value' => 'Ada']]]],
        ));

        $this->assertStringNotContainsString('variables', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    public function testPassthroughBodyHonored(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            _passthrough: [
                'body' => ['template_id' => 'tpl_1', 'send_at' => 1234567890],
            ],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('tpl_1', $decoded['template_id']);
        $this->assertSame(1234567890, $decoded['send_at']);
    }

    public function testAllSuppressed202ThrowsInvalidRecipient(): void
    {
        // developers.mailersend.com/api/v1/email.html: ALL_SUPPRESSED is a 202
        // without X-Message-Id; the email is not sent.
        $payload = [
            'message'  => 'There are some warnings for your request.',
            'warnings' => [[
                'type'       => 'ALL_SUPPRESSED',
                'warning'    => 'None of the recipients were sent this email.',
                'recipients' => [[
                    'email'   => 'recipient@example.com',
                    'name'    => 'Recipient',
                    'reasons' => ['hard_bounced'],
                ]],
            ]],
        ];
        $this->mockClient->addResponse(
            (new Response(202, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
            $this->assertSame(202, $err->statusCode);
            $this->assertIsArray($err->cause);
            $this->assertSame($payload, $err->cause['raw']);
        }
    }

    public function testSomeSuppressed202IsSuccessWithWarningsInRaw(): void
    {
        $payload = [
            'message'  => 'There are some warnings for your request.',
            'warnings' => [[
                'type'       => 'SOME_SUPPRESSED',
                'warning'    => 'Some of the recipients have been suppressed.',
                'recipients' => [[
                    'email'   => 'suppressed@example.com',
                    'name'    => 'Suppressed',
                    'reasons' => ['unsubscribed'],
                ]],
            ]],
        ];
        $this->mockClient->addResponse(
            (new Response(202, ['Content-Type' => 'application/json', 'X-Message-Id' => 'msg-7']))
                ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)))
        );

        $result = $this->buildConnector()->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Queued, $result->status);
        $this->assertSame('msg-7', $result->providerMessageId);
        $this->assertIsArray($result->raw);
        $this->assertSame('msg-7', $result->raw['messageId']);
        $this->assertSame($payload['warnings'], $result->raw['warnings']);
    }

    public function testReplyToIsSingleObject(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $this->buildConnector()->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            replyTo: 'reply@example.com',
            text: 'hi',
        ));

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['email' => 'reply@example.com'], $decoded['reply_to']);
    }

    public function testBaselineHeadersSentAsNameValueList(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $this->buildConnector()->send(new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            headers: ['X-Campaign' => 'fall', 'X-Ref' => '42'],
        ));

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [['name' => 'X-Campaign', 'value' => 'fall'], ['name' => 'X-Ref', 'value' => '42']],
            $decoded['headers'],
        );
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['message' => 'auth'], [], ProviderCode::AuthFailed, null];
        yield '422 recipient field' => [422, ['message' => 'invalid', 'errors' => ['to.0.email' => ['bad']]], [], ProviderCode::InvalidRecipient, null];
        yield '422 other field' => [422, ['message' => 'invalid', 'errors' => ['subject' => ['required']]], [], ProviderCode::InvalidRequest, null];
        yield '429 retry-after' => [429, ['message' => 'rate'], ['Retry-After' => '30'], ProviderCode::RateLimited, '30'];
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
            text: 'hi',
        );
    }

    private function buildConnector(): MailersendEmailConnector
    {
        return new MailersendEmailConnector(
            new MailersendConfig(apiToken: 'test-token'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
