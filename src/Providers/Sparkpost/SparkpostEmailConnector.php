<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sparkpost;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Base\BaseConnector;
use Thinwrap\Notifications\Contract\EmailConnectorInterface;
use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailSendResult;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Util\Passthrough;

/**
 * SparkPost email connector — POSTs JSON to
 * `https://api.sparkpost.com/api/v1/transmissions` (or EU regional host) with
 * `Authorization: <apiKey>` (raw key, NO `Bearer` prefix — SparkPost outlier).
 *
 * Mirrors the TS connector. casing-locality canonical outlier: SparkPost has no separate
 * cc/bcc fields. CC and BCC recipients are appended to `recipients[]` with
 * `address.header_to` pointing at the primary `to`. CC visibility is achieved
 * by also writing `content.headers.CC`; BCC stays invisible (never written to
 * `headers.BCC`).
 *
 * On 2xx, if `results.total_accepted_recipients === 0 && total_rejected > 0`
 * the connector throws `ConnectorError(InvalidRecipient)` so consumers handle
 * it like a 4xx from other providers.
 */
final class SparkpostEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    public readonly string $id;

    private const US_BASE = 'https://api.sparkpost.com';
    private const EU_BASE = 'https://api.eu.sparkpost.com';
    private const TRANSMISSIONS_PATH = '/api/v1/transmissions';

    public function __construct(
        private readonly SparkpostConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'sparkpost';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(EmailSendInput $input): EmailSendResult
    {
        $body = $this->buildBody($input);
        $headers = [
            // SparkPost: RAW key — no `Bearer ` prefix.
            'Authorization' => $this->config->apiKey,
            'Accept'        => 'application/json',
        ];

        $merged = Passthrough::merge(
            ['body' => $body, 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );

        $url = $this->resolveEndpoint();

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);

        $status = $response->getStatusCode();
        /** @var array<string,mixed>|null $parsed */
        $parsed = $this->decodeJson($response);

        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $parsed, $response);
        }

        // AC 9: 200 with all recipients rejected → throw InvalidRecipient.
        if (
            is_array($parsed)
            && isset($parsed['results'])
            && is_array($parsed['results'])
        ) {
            $results = $parsed['results'];
            $accepted = isset($results['total_accepted_recipients']) && is_int($results['total_accepted_recipients'])
                ? $results['total_accepted_recipients']
                : null;
            $rejected = isset($results['total_rejected_recipients']) && is_int($results['total_rejected_recipients'])
                ? $results['total_rejected_recipients']
                : 0;
            if ($accepted === 0 && $rejected > 0) {
                throw new ConnectorError(
                    providerCode: ProviderCode::InvalidRecipient,
                    providerMessage: "total_rejected_recipients={$rejected}, total_accepted_recipients=0",
                    cause: ['raw' => $parsed, 'retryAfter' => null],
                    statusCode: $status,
                );
            }
        }

        $messageId = null;
        if (
            is_array($parsed)
            && isset($parsed['results'])
            && is_array($parsed['results'])
            && isset($parsed['results']['id'])
            && is_string($parsed['results']['id'])
        ) {
            $messageId = $parsed['results']['id'];
        }

        // SparkPost accepts the transmission for asynchronous delivery (the
        // response reports accepted/rejected counts, not a synchronous send) →
        // `Queued` (vendor-faithful).
        return new EmailSendResult(
            success: true,
            status: EmailStatus::Queued,
            providerMessageId: $messageId,
            raw: $parsed ?? [],
        );
    }

    private function resolveEndpoint(): string
    {
        $base = $this->config->region === SparkpostRegion::Eu ? self::EU_BASE : self::US_BASE;
        return $base . self::TRANSMISSIONS_PATH;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildBody(EmailSendInput $input): array
    {
        $recipients = $this->buildRecipientsArray($input);

        $fromAddress = $input->from !== '' ? $input->from : $this->config->from;
        $fromObject = $this->config->senderName !== null
            ? ['email' => $fromAddress, 'name' => $this->config->senderName]
            : ['email' => $fromAddress];

        $content = [
            'from'    => $fromObject,
            'subject' => $input->subject,
        ];
        if ($input->html !== null) {
            $content['html'] = $input->html;
        }
        if ($input->text !== null) {
            $content['text'] = $input->text;
        }
        // SparkPost takes reply_to as a string, not an object.
        if ($input->replyTo !== null) {
            $content['reply_to'] = $input->replyTo;
        }

        // Headers: user-supplied + CC visibility (BCC stays invisible).
        $headers = $input->headers ?? [];
        if ($input->cc !== null && $input->cc !== []) {
            $headers['CC'] = implode(', ', $input->cc);
        }
        if ($headers !== []) {
            $content['headers'] = $headers;
        }

        if ($input->attachments !== null && $input->attachments !== []) {
            [$attachments, $inlineImages] = $this->buildAttachments($input->attachments);
            if ($attachments !== []) {
                $content['attachments'] = $attachments;
            }
            if ($inlineImages !== []) {
                $content['inline_images'] = $inlineImages;
            }
        }

        $body = ['recipients' => $recipients, 'content' => $content];

        if ($input->tags !== null && $input->tags !== []) {
            // No top-level `tags` — SparkPost convention is `metadata.tags`.
            $body['metadata'] = ['tags' => $input->tags];
        }

        return $body;
    }

    /**
     * casing-locality canonical transform: build `recipients[]`. Primary `to` recipients
     * are added without `header_to`; cc + bcc are appended with
     * `address.header_to: <primary to>`.
     *
     * @return list<array<string,mixed>>
     */
    private function buildRecipientsArray(EmailSendInput $input): array
    {
        $primaryTo = $input->to;
        $recipients = [['address' => ['email' => $primaryTo]]];

        if ($input->cc !== null) {
            foreach ($input->cc as $email) {
                $recipients[] = ['address' => ['email' => $email, 'header_to' => $primaryTo]];
            }
        }
        if ($input->bcc !== null) {
            foreach ($input->bcc as $email) {
                $recipients[] = ['address' => ['email' => $email, 'header_to' => $primaryTo]];
            }
        }

        return $recipients;
    }

    /**
     * Split attachments into regular vs inline (those with `contentId`).
     *
     * @param list<EmailAttachment> $attachments
     *
     * @return array{0:list<array<string,string>>,1:list<array<string,string>>}
     */
    private function buildAttachments(array $attachments): array
    {
        $regular = [];
        $inline = [];
        foreach ($attachments as $a) {
            $encoded = base64_encode($this->normalizeAttachmentContent($a));
            $item = ['name' => $a->filename, 'data' => $encoded];
            if ($a->contentType !== null && $a->contentType !== '') {
                $item['type'] = $a->contentType;
            }
            if ($a->contentId !== null) {
                $inline[] = $item;
            } else {
                $regular[] = $item;
            }
        }

        return [$regular, $inline];
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $firstError = null;
        if (is_array($body) && isset($body['errors']) && is_array($body['errors']) && isset($body['errors'][0]) && is_array($body['errors'][0])) {
            $firstError = $body['errors'][0];
        }

        $vendorMessage = '<no vendor message>';
        if (is_array($firstError) && isset($firstError['message']) && is_string($firstError['message'])) {
            $vendorMessage = $firstError['message'];
        }
        $code = is_array($firstError) && isset($firstError['code']) && is_string($firstError['code'])
            ? $firstError['code']
            : null;

        $providerCode = $this->classifyError($statusCode, $vendorMessage, $code);

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

    private function classifyError(int $statusCode, string $message, ?string $code): ProviderCode
    {
        if ($statusCode === 401 || $statusCode === 403 || $statusCode === 420) {
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode >= 500) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($statusCode === 413 || $statusCode === 422) {
            return ProviderCode::InvalidRequest;
        }
        if ($statusCode === 400) {
            if (preg_match('/recipient|address/i', $message) === 1) {
                return ProviderCode::InvalidRecipient;
            }
            if ($code === '1300' || $code === '1301' || $code === '1303') {
                return ProviderCode::InvalidRecipient;
            }
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
