<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sendgrid;

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
 * SendGrid v3 email connector — POSTs `https://api.sendgrid.com/v3/mail/send`
 * with Bearer auth and the nested personalizations envelope.
 *
 * Mirrors the TS connector. Casing locality (casing-locality): snake_case wire keys
 * (`personalizations`, `reply_to`, `dynamic_template_data`, `content_id`, …)
 * written directly in the array literal; no shared casing transform.
 *
 * 2xx response is 202 Accepted with empty body; `providerMessageId` is sourced
 * from the `X-Message-Id` response header (PSR-7 `getHeaderLine` returns `''`
 * when absent; coerced to `null`).
 */
final class SendgridEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

    public function __construct(
        private readonly SendgridConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'sendgrid';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(EmailSendInput $input): EmailSendResult
    {
        $body = $this->buildBody($input);
        $headers = ['Authorization' => 'Bearer ' . $this->config->apiKey];

        $merged = Passthrough::merge(
            ['body' => $body, 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );

        $url = self::ENDPOINT;

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        $messageId = $response->getHeaderLine('X-Message-Id') ?: null;

        // SendGrid returns 202 Accepted with an empty body — an accept-for-delivery
        // acknowledgement, not a synchronous send → `Queued` (vendor-faithful).
        return new EmailSendResult(
            success: true,
            status: EmailStatus::Queued,
            providerMessageId: $messageId,
            raw: ['messageId' => $messageId],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildBody(EmailSendInput $input): array
    {
        $fromAddress = $input->from !== '' ? $input->from : $this->config->from;
        $fromObject = ['email' => $fromAddress];
        if ($this->config->senderName !== null) {
            $fromObject['name'] = $this->config->senderName;
        }

        $personalization = [
            'to' => [['email' => $input->to]],
        ];
        if ($input->cc !== null && $input->cc !== []) {
            $personalization['cc'] = array_map(static fn(string $addr): array => ['email' => $addr], $input->cc);
        }
        if ($input->bcc !== null && $input->bcc !== []) {
            $personalization['bcc'] = array_map(static fn(string $addr): array => ['email' => $addr], $input->bcc);
        }

        $content = [];
        if ($input->text !== null) {
            $content[] = ['type' => 'text/plain', 'value' => $input->text];
        }
        if ($input->html !== null) {
            $content[] = ['type' => 'text/html', 'value' => $input->html];
        }

        $body = [
            'personalizations' => [$personalization],
            'from' => $fromObject,
            'subject' => $input->subject,
        ];
        if ($content !== []) {
            $body['content'] = $content;
        }
        if ($input->replyTo !== null) {
            $body['reply_to'] = ['email' => $input->replyTo];
        }
        if ($input->attachments !== null && $input->attachments !== []) {
            $body['attachments'] = $this->mapAttachments($input->attachments);
        }
        if ($input->headers !== null && $input->headers !== []) {
            $body['headers'] = $input->headers;
        }
        if ($input->tags !== null && $input->tags !== []) {
            $body['categories'] = $input->tags;
        }

        return $body;
    }

    /**
     * @param list<EmailAttachment> $attachments
     *
     * @return list<array<string,string>>
     */
    private function mapAttachments(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $a) {
            $entry = [
                'content' => base64_encode($this->normalizeAttachmentContent($a)),
                'filename' => $a->filename,
            ];
            if ($a->contentType !== null && $a->contentType !== '') {
                $entry['type'] = $a->contentType;
            }
            if ($a->contentId !== null) {
                $entry['content_id'] = $a->contentId;
                $entry['disposition'] = 'inline';
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $vendorMessage = 'SendGrid HTTP ' . $statusCode;
        if (is_array($body) && isset($body['errors'][0]['message']) && is_string($body['errors'][0]['message'])) {
            $vendorMessage = $body['errors'][0]['message'];
        }

        $providerCode = $this->classifyError($statusCode, $vendorMessage);

        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $providerMessage = $parsedSeconds !== null
            ? "{$vendorMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $vendorMessage;

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            cause: [
                'raw' => $body,
                'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
                'retryAfterSeconds' => $parsedSeconds,
            ],
            statusCode: $statusCode,
        );
    }

    private function classifyError(int $statusCode, string $message): ProviderCode
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
        if ($statusCode === 413) {
            return ProviderCode::InvalidRequest;
        }
        if ($statusCode === 400) {
            return str_contains(strtolower($message), 'email')
                ? ProviderCode::InvalidRecipient
                : ProviderCode::InvalidRequest;
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
