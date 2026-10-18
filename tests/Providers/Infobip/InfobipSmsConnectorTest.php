<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Providers\Infobip;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Providers\Infobip\InfobipConfig;
use Thinwrap\Notifications\Providers\Infobip\InfobipNarrowedInput;
use Thinwrap\Notifications\Providers\Infobip\InfobipSmsConnector;

final class InfobipSmsConnectorTest extends TestCase
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
            $this->jsonResponse(200, ['messages' => [['messageId' => 'ib-1', 'to' => '+15551234567']]])
        );
        $result = $this->buildConnector()->send($this->basicInput());

        $this->assertTrue($result->success);
        $this->assertSame(SmsStatus::Sent, $result->status);
        $this->assertSame('ib-1', $result->providerMessageId);
    }

    public function testPerAccountBaseUrl(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('https://abc123.api.infobip.com/sms/2/text/advanced', (string) $request->getUri());
    }

    public function testAppAuthHeader(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('App k', $request->getHeaderLine('Authorization'));
    }

    public function testNestedMessagesEnvelope(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $request = $this->mockClient->getRequests()[0];
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Sender', $decoded['messages'][0]['from']);
        $this->assertSame([['to' => '+15551234567']], $decoded['messages'][0]['destinations']);
        $this->assertSame('hi', $decoded['messages'][0]['text']);
    }

    public function testMissingFromThrows(): void
    {
        $connector = new InfobipSmsConnector(
            new InfobipConfig(baseUrl: 'abc123.api.infobip.com', apiKey: 'k'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
        try {
            $connector->send(new SmsSendInput(to: '+15551234567', body: 'hi'));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
        }
    }

    /**
     * Infobip gives each account a host (`xyz123.api.infobip.com`); the
     * connector sends `Authorization: App <key>` to `https://{baseUrl}/…`.
     *
     * @return iterable<string,array{string}>
     */
    public static function badBaseUrls(): iterable
    {
        yield 'scheme' => ['https://abc123.api.infobip.com'];
        yield 'trailing slash' => ['abc123.api.infobip.com/'];
        yield 'path' => ['evil.example/abc123.api.infobip.com'];
        yield 'port' => ['abc123.api.infobip.com:8443'];
        yield 'userinfo' => ['user@evil.example'];
        yield 'empty label' => ['abc123..infobip.com'];
        yield 'leading hyphen' => ['-abc.api.infobip.com'];
        yield 'empty' => [''];
        // A PCRE `$` would accept this: the whole string must match.
        yield 'trailing newline' => ["abc123.api.infobip.com\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badBaseUrls')]
    public function testBaseUrlThatIsNotABareHostnameIsRejectedBeforeSending(string $baseUrl): void
    {
        $connector = new InfobipSmsConnector(
            new InfobipConfig(baseUrl: $baseUrl, apiKey: 'k', from: 'Sender'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );

        try {
            $connector->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            if ($baseUrl !== '') {
                $this->assertStringNotContainsString($baseUrl, (string) $err->providerMessage);
            }
        }
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    public function testBareHostnameBaseUrlBuildsTheEndpoint(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send($this->basicInput());

        $this->assertSame('https://abc123.api.infobip.com/sms/2/text/advanced', (string) $this->mockClient->getRequests()[0]->getUri());
    }

    public function testServiceExceptionMapping(): void
    {
        $this->mockClient->addResponse(
            $this->jsonResponse(400, ['requestError' => ['serviceException' => ['messageId' => 'EC_INVALID', 'text' => 'bad number']]])
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            $this->assertSame('bad number', $err->providerMessage);
        }
    }

    public function testRetryAfter(): void
    {
        $this->mockClient->addResponse(
            (new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '5']))
                ->withBody($this->factory->createStream(json_encode(['requestError' => ['serviceException' => ['text' => 'rate']]], JSON_THROW_ON_ERROR)))
        );
        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::RateLimited, $err->providerCode);
            $this->assertStringContainsString('Retry-After: 5 seconds', (string) $err->providerMessage);
        }
    }

    public function testPendingStatusIsSuccess(): void
    {
        $this->mockClient->addResponse($this->jsonResponse(200, [
            'bulkId'   => 'bulk-1',
            'messages' => [[
                'messageId'   => 'ib-2',
                'destination' => '+15551234567',
                'status'      => [
                    'groupId'     => 1,
                    'groupName'   => 'PENDING',
                    'name'        => 'PENDING_ACCEPTED',
                    'description' => 'Message sent to next instance',
                ],
            ]],
        ]));

        $result = $this->buildConnector()->send($this->basicInput());
        $this->assertTrue($result->success);
        $this->assertSame('ib-2', $result->providerMessageId);
    }

    /**
     * @return iterable<string,array{string,ProviderCode}>
     */
    public static function rejectedStatusProvider(): iterable
    {
        yield 'REJECTED_DESTINATION' => ['REJECTED_DESTINATION', ProviderCode::InvalidRecipient];
        yield 'REJECTED_PREFIX_MISSING' => ['REJECTED_PREFIX_MISSING', ProviderCode::InvalidRecipient];
        yield 'MISSING_TO' => ['MISSING_TO', ProviderCode::InvalidRecipient];
        yield 'REJECTED_DND' => ['REJECTED_DND', ProviderCode::InvalidRecipient];
        yield 'REJECTED_FLOODING_FILTER' => ['REJECTED_FLOODING_FILTER', ProviderCode::RateLimited];
        yield 'REJECTED_NOT_ENOUGH_CREDITS' => ['REJECTED_NOT_ENOUGH_CREDITS', ProviderCode::InvalidRequest];
        yield 'REJECTED_SENDER' => ['REJECTED_SENDER', ProviderCode::InvalidRequest];
    }

    #[DataProvider('rejectedStatusProvider')]
    public function testRejectedStatusOn200Throws(string $name, ProviderCode $expected): void
    {
        $payload = [
            'bulkId'   => 'bulk-1',
            'messages' => [[
                'messageId' => 'ib-3',
                'to'        => '+15551234567',
                'status'    => [
                    'groupId'     => 5,
                    'groupName'   => 'REJECTED',
                    'name'        => $name,
                    'description' => 'Message rejected',
                ],
            ]],
        ];
        $this->mockClient->addResponse($this->jsonResponse(200, $payload));

        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame($expected, $err->providerCode);
            $this->assertSame(200, $err->statusCode);
            $this->assertStringContainsString($name, (string) $err->providerMessage);
            $this->assertIsArray($err->cause);
            $this->assertSame($payload, $err->cause['raw']);
        }
    }

    public function testRejectedGroupNameWithoutGroupIdThrows(): void
    {
        $this->mockClient->addResponse($this->jsonResponse(200, [
            'messages' => [['messageId' => 'ib-4', 'status' => ['groupName' => 'REJECTED', 'name' => 'REJECTED_NOT_REGISTERED']]],
        ]));

        try {
            $this->buildConnector()->send($this->basicInput());
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRecipient, $err->providerCode);
        }
    }

    public function testScheduleSendAtIsPerMessage(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send(new InfobipNarrowedInput(
            to: '+15551234567',
            body: 'hi',
            from: 'Sender',
            bulkId: 'bulk-9',
            scheduleSettings: ['sendAt' => '2026-10-01T09:00:00.000+0000'],
        ));

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2026-10-01T09:00:00.000+0000', $decoded['messages'][0]['sendAt']);
        $this->assertArrayNotHasKey('sendingDateTime', $decoded);
        $this->assertArrayNotHasKey('sendAt', $decoded);
        $this->assertSame('bulk-9', $decoded['bulkId']);
    }

    public function testLanguageIsLanguageCodeObject(): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send(new InfobipNarrowedInput(
            to: '+15551234567',
            body: 'hi',
            from: 'Sender',
            language: 'TR',
        ));

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['languageCode' => 'TR'], $decoded['messages'][0]['language']);
    }

    /**
     * @return iterable<string,array{int,?string,int}>
     */
    public static function validityProvider(): iterable
    {
        yield 'no unit = minutes' => [90, null, 90];
        yield 'MINUTES' => [90, 'MINUTES', 90];
        yield 'HOURS' => [2, 'HOURS', 120];
        yield 'SECONDS rounds up' => [90, 'SECONDS', 2];
        yield 'seconds lowercase' => [120, 'seconds', 2];
    }

    #[DataProvider('validityProvider')]
    public function testValidityPeriodIsSentInMinutesWithoutTimeUnit(int $period, ?string $unit, int $expectedMinutes): void
    {
        $this->mockClient->addResponse($this->successResponse());
        $this->buildConnector()->send(new InfobipNarrowedInput(
            to: '+15551234567',
            body: 'hi',
            from: 'Sender',
            validityPeriod: $period,
            validityPeriodTimeUnit: $unit,
        ));

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($expectedMinutes, $decoded['messages'][0]['validityPeriod']);
        $this->assertArrayNotHasKey('validityPeriodTimeUnit', $decoded['messages'][0]);
    }

    public function testUnknownValidityUnitThrowsBeforeDispatch(): void
    {
        try {
            $this->buildConnector()->send(new InfobipNarrowedInput(
                to: '+15551234567',
                body: 'hi',
                from: 'Sender',
                validityPeriod: 1,
                validityPeriodTimeUnit: 'DAYS',
            ));
            $this->fail('Expected ConnectorError');
        } catch (ConnectorError $err) {
            $this->assertSame(ProviderCode::InvalidRequest, $err->providerCode);
            $this->assertCount(0, $this->mockClient->getRequests());
        }
    }

    private function basicInput(): SmsSendInput
    {
        return new SmsSendInput(to: '+15551234567', body: 'hi', from: 'Sender');
    }

    private function buildConnector(): InfobipSmsConnector
    {
        return new InfobipSmsConnector(
            new InfobipConfig(baseUrl: 'abc123.api.infobip.com', apiKey: 'k', from: 'Sender'),
            $this->mockClient,
            $this->factory,
            $this->factory,
        );
    }

    private function successResponse(): Response
    {
        return $this->jsonResponse(200, ['messages' => [['messageId' => 'm']]]);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function jsonResponse(int $status, array $body): Response
    {
        return (new Response($status, ['Content-Type' => 'application/json']))
            ->withBody($this->factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
