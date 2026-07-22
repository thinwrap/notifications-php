<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Twilio;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Base\BaseConnector;
use Thinwrap\Notifications\Contract\SmsConnectorInterface;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsSendResult;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Util\Passthrough;

/**
 * Twilio SMS connector — POSTs `application/x-www-form-urlencoded` body in
 * PascalCase wire shape, auths with HTTP Basic (`accountSid:authToken`), and
 * targets `https://<regional-host>/2010-04-01/Accounts/<accountSid>/Messages.json`.
 *
 * Mirrors the TS connector. PascalCase wire keys are written explicitly per
 * the casing-locality rule. `MediaUrl` for MMS is array-valued and encoded as
 * multiple form fields by `BaseConnector::sendPostForm`'s repeated-key
 * support.
 */
final class TwilioSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    public readonly string $id;

    /** @var array<string,string> */
    private const BASE_HOSTS = [
        'us1' => 'api.twilio.com',
        'us2' => 'api.us2.twilio.com',
        'ie1' => 'api.ie1.twilio.com',
        'au1' => 'api.au1.twilio.com',
        'br1' => 'api.br1.twilio.com',
        'de1' => 'api.de1.twilio.com',
        'jp1' => 'api.jp1.twilio.com',
        'sg1' => 'api.sg1.twilio.com',
    ];

    public function __construct(
        private readonly TwilioConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'twilio';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(SmsSendInput $input): SmsSendResult
    {
        $from = $input->from ?? $this->config->from;
        $messagingServiceSid = $input instanceof TwilioNarrowedInput ? $input->messagingServiceSid : null;

        if (($from === null || $from === '') && ($messagingServiceSid === null || $messagingServiceSid === '')) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Twilio requires either `from`, `config.from`, or `messagingServiceSid`.',
                statusCode: 400,
            );
        }

        /** @var array<string,scalar|list<scalar>|null> $form */
        $form = [
            'To'   => $input->to,
            'Body' => $input->body,
        ];
        if ($from !== null && $from !== '') {
            $form['From'] = $from;
        }
        if ($input instanceof TwilioNarrowedInput) {
            if ($input->messagingServiceSid !== null) {
                $form['MessagingServiceSid'] = $input->messagingServiceSid;
            }
            if ($input->mediaUrl !== null && $input->mediaUrl !== []) {
                $form['MediaUrl'] = $input->mediaUrl;
            }
            if ($input->statusCallback !== null) {
                $form['StatusCallback'] = $input->statusCallback;
            }
            if ($input->applicationSid !== null) {
                $form['ApplicationSid'] = $input->applicationSid;
            }
            if ($input->maxPrice !== null) {
                $form['MaxPrice'] = $input->maxPrice;
            }
            if ($input->provideFeedback !== null) {
                $form['ProvideFeedback'] = $input->provideFeedback ? 'true' : 'false';
            }
            if ($input->validityPeriod !== null) {
                $form['ValidityPeriod'] = (string) $input->validityPeriod;
            }
            if ($input->forceDelivery !== null) {
                $form['ForceDelivery'] = $input->forceDelivery ? 'true' : 'false';
            }
            if ($input->contentRetention !== null) {
                $form['ContentRetention'] = $input->contentRetention;
            }
            if ($input->addressRetention !== null) {
                $form['AddressRetention'] = $input->addressRetention;
            }
            if ($input->smartEncoded !== null) {
                $form['SmartEncoded'] = $input->smartEncoded ? 'true' : 'false';
            }
            if ($input->persistentAction !== null && $input->persistentAction !== []) {
                $form['PersistentAction'] = $input->persistentAction;
            }
            if ($input->shortenUrls !== null) {
                $form['ShortenUrls'] = $input->shortenUrls ? 'true' : 'false';
            }
            if ($input->scheduleType !== null) {
                $form['ScheduleType'] = $input->scheduleType;
            }
            if ($input->sendAt !== null) {
                $form['SendAt'] = $input->sendAt;
            }
            if ($input->sendAsMms !== null) {
                $form['SendAsMms'] = $input->sendAsMms ? 'true' : 'false';
            }
            if ($input->contentVariables !== null) {
                $form['ContentVariables'] = $input->contentVariables;
            }
            if ($input->riskCheck !== null) {
                $form['RiskCheck'] = $input->riskCheck;
            }
            if ($input->contentSid !== null) {
                $form['ContentSid'] = $input->contentSid;
            }
        }

        $authHeader = 'Basic ' . base64_encode("{$this->config->accountSid}:{$this->config->authToken}");
        $headers = ['Authorization' => $authHeader];

        $merged = Passthrough::merge(
            ['body' => [], 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );
        /** @var array<string,mixed> $passthroughBody */
        $passthroughBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        // Apply passthrough body keys (scalar or list<scalar>) on top of form.
        foreach ($passthroughBody as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_array($v) && self::isListOfScalars($v)) {
                /** @var list<scalar> $v */
                $form[$k] = $v;
            } elseif (is_scalar($v)) {
                $form[$k] = $v;
            }
        }

        $regionValue = $this->config->region->value ?? 'us1';
        $host = self::BASE_HOSTS[$regionValue];
        $url = "https://{$host}/2010-04-01/Accounts/{$this->config->accountSid}/Messages.json";

        $response = $this->sendPostForm($url, $form, $mergedHeaders);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        /** @var array<string,mixed>|null $parsed */
        $parsed = $this->decodeJson($response);
        $messageId = is_array($parsed) && isset($parsed['sid']) && is_string($parsed['sid'])
            ? $parsed['sid']
            : null;

        return new SmsSendResult(
            success: true,
            status: SmsStatus::Sent,
            providerMessageId: $messageId,
            raw: $parsed,
        );
    }

    /**
     * @param array<int|string,mixed> $value
     */
    private static function isListOfScalars(array $value): bool
    {
        if (!array_is_list($value)) {
            return false;
        }
        foreach ($value as $v) {
            if (!is_scalar($v)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $vendorMessage = "Twilio HTTP {$statusCode}";
        $twilioCode = null;
        if (is_array($body)) {
            if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                $vendorMessage = $body['message'];
            }
            if (isset($body['code']) && (is_int($body['code']) || is_numeric($body['code']))) {
                $twilioCode = (int) $body['code'];
            }
        }

        $providerCode = $this->classifyError($statusCode, $twilioCode);

        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $providerMessage = $parsedSeconds !== null
            ? "{$vendorMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $vendorMessage;

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            cause: [
                'raw'       => $body,
                'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
                'retryAfterSeconds' => $parsedSeconds,
            ],
            statusCode: $statusCode,
        );
    }

    private function classifyError(int $statusCode, ?int $twilioCode): ProviderCode
    {
        if ($statusCode === 401 || $statusCode === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode >= 500) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($statusCode === 400 && $twilioCode !== null) {
            if (in_array($twilioCode, [21211, 21408, 21610, 21612, 21614], true)) {
                return ProviderCode::InvalidRecipient;
            }
            if ($twilioCode === 20003) {
                return ProviderCode::AuthFailed;
            }
        }
        if ($statusCode >= 400) {
            return ProviderCode::InvalidRequest;
        }
        return ProviderCode::Unknown;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJson(ResponseInterface $response): ?array
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
