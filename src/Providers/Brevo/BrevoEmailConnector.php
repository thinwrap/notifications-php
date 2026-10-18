<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Brevo;

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
 * Brevo (ex-Sendinblue) email connector — POSTs JSON to
 * `https://api.brevo.com/v3/smtp/email` with `api-key: <apiKey>` auth.
 *
 * Mirrors the TS connector. Wire keys are camelCase (matching Thinwrap input
 * shape); field renames (`from` → `sender`, `html` → `htmlContent`,
 * `text` → `textContent`, `attachments` → `attachment` singular) are
 * hand-mapped per the casing-locality rule.
 *
 * **Attachment pre-flight guard:** if any `attachments[].contentId` is set, throws
 * `ConnectorError(InvalidRequest, statusCode: null)` BEFORE any HTTP call —
 * Brevo's `/v3/smtp/email` has no first-class cid mechanism. This is the
 * canonical "vendor doesn't support a baseline field" throw, consistent with
 * the ≥90%-of-providers baseline rule.
 */
final class BrevoEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(
        #[\SensitiveParameter]
        private readonly BrevoConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'brevo';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(EmailSendInput $input): EmailSendResult
    {
        // Attachment pre-flight: Brevo cannot do inline cid. Throw before any HTTP.
        if ($input->attachments !== null) {
            foreach ($input->attachments as $a) {
                if ($a->contentId !== null) {
                    throw new ConnectorError(
                        providerCode: ProviderCode::InvalidRequest,
                        providerMessage: 'Brevo does not support inline cid attachments via API; use Brevo template substitutions via _passthrough or pick another provider.',
                        statusCode: null,
                    );
                }
            }
        }

        $body = $this->buildBody($input);
        $headers = [
            'api-key' => $this->config->apiKey,
            'Accept'  => 'application/json',
        ];

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
        /** @var array<string,mixed>|null $parsed */
        $parsed = $this->decodeJson($response);

        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $parsed, $response);
        }

        $messageId = is_array($parsed) && isset($parsed['messageId']) && is_string($parsed['messageId'])
            ? $parsed['messageId']
            : null;

        // Brevo returns the assigned messageId synchronously on the send call →
        // `Sent` (vendor-faithful).
        return new EmailSendResult(
            success: true,
            status: EmailStatus::Sent,
            providerMessageId: $messageId,
            raw: $parsed,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildBody(EmailSendInput $input): array
    {
        $fromAddress = $input->from !== '' ? $input->from : $this->config->from;
        $sender = $this->config->senderName !== null
            ? ['name' => $this->config->senderName, 'email' => $fromAddress]
            : ['email' => $fromAddress];

        $body = [
            'sender'  => $sender,
            'to'      => [['email' => $input->to]],
            'subject' => $input->subject,
        ];

        if ($input->cc !== null && $input->cc !== []) {
            $body['cc'] = array_map(static fn(string $e): array => ['email' => $e], $input->cc);
        }
        if ($input->bcc !== null && $input->bcc !== []) {
            $body['bcc'] = array_map(static fn(string $e): array => ['email' => $e], $input->bcc);
        }
        if ($input->html !== null) {
            $body['htmlContent'] = $input->html;
        }
        if ($input->text !== null) {
            $body['textContent'] = $input->text;
        }
        if ($input->replyTo !== null) {
            $body['replyTo'] = ['email' => $input->replyTo];
        }
        if ($input->headers !== null && $input->headers !== []) {
            $body['headers'] = $input->headers;
        }
        if ($input->tags !== null && $input->tags !== []) {
            $body['tags'] = $input->tags;
        }
        if ($input->attachments !== null && $input->attachments !== []) {
            $body['attachment'] = $this->buildAttachments($input->attachments);
        }

        return $body;
    }

    /**
     * @param list<EmailAttachment> $attachments
     *
     * @return list<array<string,string>>
     */
    private function buildAttachments(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $a) {
            $encoded = base64_encode($this->normalizeAttachmentContent($a));
            // Brevo's attachment shape: { name, content }. ContentType implicit
            // from filename per docs; contentId rejected at top of send().
            $out[] = ['name' => $a->filename, 'content' => $encoded];
        }

        return $out;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $vendorMessage = '<no vendor message>';
        if (is_array($body) && isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            $vendorMessage = $body['message'];
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
                'raw'       => $body,
                'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
                'retryAfterSeconds' => $parsedSeconds,
            ],
            statusCode: $statusCode,
        );
    }

    private function classifyError(int $statusCode, string $message): ProviderCode
    {
        if ($statusCode === 401 || $statusCode === 402 || $statusCode === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode >= 500) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($statusCode === 400) {
            return preg_match('/recipient|to\[|email address|sender/i', $message) === 1
                ? ProviderCode::InvalidRecipient
                : ProviderCode::InvalidRequest;
        }
        if ($statusCode === 404) {
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
