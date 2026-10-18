<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Mailtrap;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Mailtrap\MailtrapConfig;
use Thinwrap\Notifications\Providers\Mailtrap\MailtrapEmailConnector;
use Thinwrap\Notifications\Providers\Mailtrap\MailtrapMode;

final class MailtrapEmailConnectorTest extends TestCase
{
    private MockClient $mockClient;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testSandboxMissingInboxIdThrowsAtConstruct(): void
    {
        try {
            new MailtrapEmailConnector(
                new MailtrapConfig(apiToken: 't', mode: MailtrapMode::Sandbox),
                $this->mockClient,
                $this->factory,
                $this->factory,
            );
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            $this->assertStringContainsString('inboxId', (string) $err->providerMessage);
        }
    }

    public function testProductionWithInboxIdThrowsAtConstruct(): void
    {
        try {
            new MailtrapEmailConnector(
                new MailtrapConfig(apiToken: 't', mode: MailtrapMode::Production, inboxId: 'abc'),
                $this->mockClient,
                $this->factory,
                $this->factory,
            );
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            $this->assertStringContainsString('forbids', (string) $err->providerMessage);
        }
    }

    /** Sandbox is `POST /api/send/{inbox_id}`: an empty id would build `/api/send/`. */
    public function testSandboxWithEmptyInboxIdThrowsAtConstruct(): void
    {
        try {
            new MailtrapEmailConnector(
                new MailtrapConfig(apiToken: 't', mode: MailtrapMode::Sandbox, inboxId: ''),
                $this->mockClient,
                $this->factory,
                $this->factory,
            );
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            $this->assertStringContainsString('inboxId', (string) $err->providerMessage);
        }
    }

    public function testProductionWithEmptyInboxIdIsAccepted(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new MailtrapEmailConnector(
            new MailtrapConfig(apiToken: 't', mode: MailtrapMode::Production, inboxId: ''),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $connector->send($this->basicInput());

        $this->assertSame('https://send.api.mailtrap.io/api/send', (string) $this->mockClient->getRequests()[0]->getUri());
    }

    /**
     * Mailtrap send schema: plain variants carry `category` (string) and
     * `custom_variables` (object); `EmailFromTemplate` carries `template_uuid`,
     * `template_variables` and `custom_variables` but no subject/text/html/category.
     */
    public function testNarrowedFieldsMapToMailtrapWireNames(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildProduction();

        $connector->send(new \Thinwrap\Notifications\Providers\Mailtrap\MailtrapNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Hi',
            text: 'hi',
            category: 'welcome',
            customVariables: ['user_id' => '42'],
        ));

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('welcome', $body['category']);
        $this->assertSame(['user_id' => '42'], $body['custom_variables']);
        $this->assertSame('Hi', $body['subject']);
    }

    public function testTemplateSendDropsSubjectAndBodies(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildProduction();

        $connector->send(new \Thinwrap\Notifications\Providers\Mailtrap\MailtrapNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'unused',
            text: 'unused',
            html: '<p>unused</p>',
            customVariables: ['user_id' => '42'],
            templateUuid: '7c1b6c2e-1f7a-4a4e-9a55-0e3b9b1c2d3e',
            templateVariables: ['name' => 'Ada'],
        ));

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('7c1b6c2e-1f7a-4a4e-9a55-0e3b9b1c2d3e', $body['template_uuid']);
        $this->assertSame(['name' => 'Ada'], $body['template_variables']);
        $this->assertSame(['user_id' => '42'], $body['custom_variables']);
        $this->assertArrayNotHasKey('subject', $body);
        $this->assertArrayNotHasKey('text', $body);
        $this->assertArrayNotHasKey('html', $body);
    }

    /** Mailtrap OpenAPI: `custom_variables` and `template_variables` are objects. */
    public function testEmptyVariableMapsAreSentAsJsonObjects(): void
    {
        $this->mockClient->addResponse($this->successResponse());

        $this->buildProduction()->send(new \Thinwrap\Notifications\Providers\Mailtrap\MailtrapNarrowedInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'unused',
            customVariables: [],
            templateUuid: '7c1b6c2e-1f7a-4a4e-9a55-0e3b9b1c2d3e',
            templateVariables: [],
        ));

        $raw = (string) $this->mockClient->getRequests()[0]->getBody();
        $this->assertStringContainsString('"custom_variables":{}', $raw);
        $this->assertStringContainsString('"template_variables":{}', $raw);
    }

    public function testTemplateSendWithCategoryIsRejectedBeforeSending(): void
    {
        $connector = $this->buildProduction();

        try {
            $connector->send(new \Thinwrap\Notifications\Providers\Mailtrap\MailtrapNarrowedInput(
                from: 'sender@example.com',
                to: 'recipient@example.com',
                subject: 'unused',
                category: 'welcome',
                templateUuid: '7c1b6c2e-1f7a-4a4e-9a55-0e3b9b1c2d3e',
            ));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    public function testSandboxHitsSandboxHost(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = new MailtrapEmailConnector(
            new MailtrapConfig(apiToken: 't', mode: MailtrapMode::Sandbox, inboxId: 'abc'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://sandbox.api.mailtrap.io/api/send/abc', (string) $request->getUri());
    }

    public function testProductionHitsProductionHost(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildProduction();

        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://send.api.mailtrap.io/api/send', (string) $request->getUri());
    }

    public function testBearerAuthHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildProduction();
        $connector->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testBodyShape(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildProduction();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Hi',
            cc: ['c@x'],
            bcc: ['b@x'],
            replyTo: 'rt@x',
            text: 'hi',
            html: '<p>hi</p>',
            headers: ['X-Foo' => 'bar'],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['email' => 'sender@example.com'], $decoded['from']);
        $this->assertSame([['email' => 'recipient@example.com']], $decoded['to']);
        $this->assertSame([['email' => 'c@x']], $decoded['cc']);
        $this->assertSame([['email' => 'b@x']], $decoded['bcc']);
        $this->assertSame(['email' => 'rt@x'], $decoded['reply_to']);
        $this->assertSame('hi', $decoded['text']);
        $this->assertSame('<p>hi</p>', $decoded['html']);
        $this->assertSame(['X-Foo' => 'bar'], $decoded['headers']);
    }

    public function testHappyPathReturnsMessageId(): void
    {
        $this->mockClient->addResponse(
            (new Response(200, ['Content-Type' => 'application/json']))
                ->withBody($this->factory->createStream(json_encode(['success' => true, 'message_ids' => ['m1']], JSON_THROW_ON_ERROR)))
        );
        $connector = $this->buildProduction();

        $result = $connector->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(EmailStatus::Sent, $result->status);
        $this->assertSame('m1', $result->providerMessageId);
    }

    public function testPassthroughHonored(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $connector = $this->buildProduction();

        $input = new EmailSendInput(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test',
            text: 'hi',
            _passthrough: ['body' => ['template_uuid' => 'tpl-1']],
        );
        $connector->send($input);

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('tpl-1', $decoded['template_uuid']);
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:array<string,string>,3:ProviderCode,4:string|null}>
     */
    public static function errorScenarios(): iterable
    {
        yield '401' => [401, ['errors' => ['unauthorized']], [], ProviderCode::AuthFailed, null];
        yield '422 recipient' => [422, ['errors' => ['Invalid recipient address']], [], ProviderCode::InvalidRecipient, null];
        yield '422 other' => [422, ['errors' => ['bad subject']], [], ProviderCode::InvalidRequest, null];
        yield '400 email phrase' => [400, ['errors' => ['Invalid email']], [], ProviderCode::InvalidRecipient, null];
        yield '429 retry-after' => [429, ['errors' => ['rate']], ['Retry-After' => '45'], ProviderCode::RateLimited, '45'];
        yield '500' => [500, ['errors' => ['oops']], [], ProviderCode::ProviderUnavailable, null];
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
        $connector = $this->buildProduction();

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
            subject: 'Hi',
            text: 'hi',
        );
    }

    private function buildProduction(): MailtrapEmailConnector
    {
        return new MailtrapEmailConnector(
            new MailtrapConfig(apiToken: 'test-token', mode: MailtrapMode::Production),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return (new Response(200, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode(['success' => true, 'message_ids' => ['m']], JSON_THROW_ON_ERROR)));
    }
}
