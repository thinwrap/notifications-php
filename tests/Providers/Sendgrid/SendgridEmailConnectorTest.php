<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Sendgrid;

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
use Thinwrap\Notifications\Providers\Sendgrid\SendgridConfig;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridEmailConnector;

final class SendgridEmailConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testHappyPath202WithXMessageIdHeader(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'SG.abc123']));
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Queued, $result->status);
        $this->assertSame('SG.abc123', $result->providerMessageId);
    }

    public function testEmptyXMessageIdHeaderCoercedToNull(): void
    {
        $this->mockClient->addResponse(new Response(202));
        $connector = $this->buildConnector();

        $result = $connector->send($this->basicInput());

        $this->assertNull($result->providerMessageId);
    }

    public function testBodyShapeNestedEnvelope(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('personalizations', $decoded);
        $this->assertArrayHasKey('from', $decoded);
        $this->assertArrayHasKey('subject', $decoded);
        $this->assertArrayHasKey('content', $decoded);
        $this->assertSame([['email' => 'recipient@example.com']], $decoded['personalizations'][0]['to']);
    }

    public function testFromComposesSenderName(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = new SendgridEmailConnector(
            new SendgridConfig(apiKey: 'k', from: 'no-reply@acme.io', senderName: 'Acme'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        // Per-message $input->from is required and wins over config->from;
        // config->senderName still supplies the display name.
        $this->assertSame('sender@example.com', $decoded['from']['email']);
        $this->assertSame('Acme', $decoded['from']['name']);
    }

    public function testToCcBccNormalization(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();
        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'a@x',
            subject: 'Test',
            cc: ['c@x'],
            bcc: ['b@x'],
            text: 'hi',
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['email' => 'a@x']], $decoded['personalizations'][0]['to']);
        $this->assertSame([['email' => 'c@x']], $decoded['personalizations'][0]['cc']);
        $this->assertSame([['email' => 'b@x']], $decoded['personalizations'][0]['bcc']);
    }

    public function testAttachmentMapping(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();

        $attachment = new EmailAttachment(
            filename: 'file.pdf',
            contentType: 'application/pdf',
            content: 'binarydata',
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
        $this->assertSame(base64_encode('binarydata'), $att['content']);
        $this->assertSame('application/pdf', $att['type']);
        $this->assertSame('cid1', $att['content_id']);
        $this->assertSame('inline', $att['disposition']);
    }

    /**
     * SendGrid v3 mail/send (twilio/sendgrid-oai tsg_mail_v3.json): top-level
     * `template_id`, `send_at`, `batch_id`, `asm {group_id, groups_to_display}`,
     * `mail_settings`, `tracking_settings`, `ip_pool_name`, `categories`
     * ("uniqueItems"), and `personalizations[].dynamic_template_data`.
     */
    public function testNarrowedFieldsMapToV3WireNames(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'sg-1']));
        $connector = $this->buildConnector();

        $connector->send(new \Thinwrap\Notifications\Providers\Sendgrid\SendgridNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
            tags: ['welcome', 'onboarding'],
            templateId: 'd-123',
            dynamicTemplateData: ['name' => 'Ada'],
            sendAt: 1_790_000_000,
            batchId: 'batch-1',
            asm: ['group_id' => 42, 'groups_to_display' => [42, 43]],
            mailSettings: ['sandbox_mode' => ['enable' => true]],
            trackingSettings: ['click_tracking' => ['enable' => false]],
            ipPoolName: 'transactional',
            categories: ['onboarding', 'q3'],
        ));

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('d-123', $body['template_id']);
        $this->assertSame(['name' => 'Ada'], $body['personalizations'][0]['dynamic_template_data']);
        $this->assertSame([['email' => 'recipient@example.com']], $body['personalizations'][0]['to']);
        $this->assertSame(1_790_000_000, $body['send_at']);
        $this->assertSame('batch-1', $body['batch_id']);
        $this->assertSame(['group_id' => 42, 'groups_to_display' => [42, 43]], $body['asm']);
        $this->assertSame(['sandbox_mode' => ['enable' => true]], $body['mail_settings']);
        $this->assertSame(['click_tracking' => ['enable' => false]], $body['tracking_settings']);
        $this->assertSame('transactional', $body['ip_pool_name']);
        $this->assertSame(['welcome', 'onboarding', 'q3'], $body['categories']);
    }

    /** OAI spec: dynamic_template_data, asm, mail_settings and tracking_settings are objects. */
    public function testEmptyObjectFieldsAreSentAsJsonObjects(): void
    {
        $this->mockClient->addResponse(new Response(202));

        $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Sendgrid\SendgridNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'S',
            text: 'B',
            dynamicTemplateData: [],
            mailSettings: [],
            trackingSettings: [],
        ));

        $raw = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringContainsString('"dynamic_template_data":{}', $raw);
        $this->assertStringContainsString('"mail_settings":{}', $raw);
        $this->assertStringContainsString('"tracking_settings":{}', $raw);
    }

    /** OAI spec: categories `maxItems: 10`, `uniqueItems: true`; sendgrid-php: "can not exceed 10". */
    public function testMoreThanTenCategoriesIsRejectedBeforeSending(): void
    {
        try {
            $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Sendgrid\SendgridNarrowedInput(
                from: 'sender@example.com',
                to: 'recipient@example.com',
                subject: 'S',
                text: 'B',
                tags: ['t1', 't2', 't3'],
                categories: ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8'],
            ));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $e) {
            $this->assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    /** OAI `maxLength: 255` counts characters (JSON Schema), not UTF-8 bytes. */
    public function testCategoryLengthIsCountedInCharacters(): void
    {
        $this->mockClient->addResponse(new Response(202));
        $this->buildConnector()->send(new EmailSendInput(from: 'sender@example.com', to: 'r@example.com', subject: 'S', text: 'B', tags: [str_repeat('я', 255)]));
        $this->assertCount(1, $this->mockClient->getRequests());

        try {
            $this->buildConnector()->send(new EmailSendInput(from: 'sender@example.com', to: 'r@example.com', subject: 'S', text: 'B', tags: [str_repeat('я', 256)]));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $e) {
            $this->assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
        $this->assertCount(1, $this->mockClient->getRequests());
    }

    /** `uniqueItems` compares strings exactly: "1", "01" and "1.0" are three categories. */
    public function testNumericLookingCategoriesAreNotMerged(): void
    {
        $this->mockClient->addResponse(new Response(202));

        $this->buildConnector()->send(new EmailSendInput(from: 'sender@example.com', to: 'r@example.com', subject: 'S', text: 'B', tags: ['1', '01', '1.0', '1']));

        $this->assertStringContainsString('"categories":["1","01","1.0"]', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    public function testBaseTagsAreDeduplicated(): void
    {
        $this->mockClient->addResponse(new Response(202));

        $this->buildConnector()->send(new EmailSendInput(from: 'sender@example.com', to: 'r@example.com', subject: 'S', text: 'B', tags: ['a', 'a', 'b']));

        $this->assertStringContainsString('"categories":["a","b"]', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    /** OAI spec: top-level `subject` has `minLength: 1`; a dynamic template supplies its own. */
    public function testEmptySubjectIsOmittedOnATemplateSend(): void
    {
        $this->mockClient->addResponse(new Response(202));

        $this->buildConnector()->send(new \Thinwrap\Notifications\Providers\Sendgrid\SendgridNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: '',
            templateId: 'd-123',
        ));

        $this->assertStringNotContainsString('"subject"', (string) $this->mockClient->getRequests()[0]->getBody());
    }

    public function testUnsetNarrowedFieldsAreNotSent(): void
    {
        $this->mockClient->addResponse(new Response(202));
        $connector = $this->buildConnector();

        $connector->send(new \Thinwrap\Notifications\Providers\Sendgrid\SendgridNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'Body',
        ));

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        foreach (['template_id', 'send_at', 'batch_id', 'asm', 'mail_settings', 'tracking_settings', 'ip_pool_name', 'categories'] as $key) {
            $this->assertArrayNotHasKey($key, $body);
        }
        $this->assertArrayNotHasKey('dynamic_template_data', $body['personalizations'][0]);
    }

    public function testPassthroughHonored(): void
    {
        $this->mockClient->addResponse(new Response(202, ['X-Message-Id' => 'x']));
        $connector = $this->buildConnector();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            _passthrough: [
                'body' => [
                    'template_id' => 'd-abc',
                    'personalizations' => [['dynamic_template_data' => ['firstName' => 'Alex']]],
                ],
            ],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('d-abc', $decoded['template_id']);
        $this->assertSame('Alex', $decoded['personalizations'][0]['dynamic_template_data']['firstName']);
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['errors' => [['message' => 'auth']]], [], ProviderCode::AuthFailed, null];
        yield '429 retry-after' => [429, ['errors' => [['message' => 'rate']]], ['Retry-After' => '60'], ProviderCode::RateLimited, '60'];
        yield '500' => [500, ['errors' => [['message' => 'oops']]], [], ProviderCode::ProviderUnavailable, null];
        yield '400 email msg' => [400, ['errors' => [['message' => 'Invalid email address']]], [], ProviderCode::InvalidRecipient, null];
        yield '400 other' => [400, ['errors' => [['message' => 'bad request']]], [], ProviderCode::InvalidRequest, null];
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

    private function buildConnector(): SendgridEmailConnector
    {
        return new SendgridEmailConnector(
            new SendgridConfig(apiKey: 'test-key', from: 'sender@example.com'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }
}
