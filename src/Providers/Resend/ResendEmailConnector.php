<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Resend;

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
use Thinwrap\Notifications\Util\Mailbox;
use Thinwrap\Notifications\Util\Passthrough;

/**
 * Resend email connector — POSTs `https://api.resend.com/emails` with Bearer
 * auth and Resend's lowercase JSON body shape.
 *
 * Mirrors the TS connector (`notifications-ts/src/providers/resend/resend.connector.ts`).
 * Casing locality (casing-locality): wire keys (`from`, `to`, `subject`, `html`,
 * `text`, `cc`, `bcc`, `reply_to`, `headers`, `tags`, `attachments`) written
 * directly in the array literal; no shared casing transform.
 */
final class ResendEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://api.resend.com/emails';

    public function __construct(
        #[\SensitiveParameter]
        private readonly ResendConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'resend';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(EmailSendInput $input): EmailSendResult
    {
        $connectorBody = $this->buildBody($input);
        $connectorHeaders = $this->buildHeaders($input);

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => $connectorHeaders, 'query' => []],
            $input->_passthrough,
        );

        $url = self::ENDPOINT;

        /** @var array<string,mixed> $body */
        $body = $merged['body'];
        /** @var array<string,string> $headers */
        $headers = $merged['headers'];

        $response = $this->sendPostJson($url, $body, $headers);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        /** @var array<string,mixed>|null $parsed */
        $parsed = $this->decodeJson($response);
        $messageId = null;
        if (is_array($parsed) && isset($parsed['id']) && is_string($parsed['id'])) {
            $messageId = $parsed['id'];
        }

        // Resend returns the assigned email id synchronously → `Sent`
        // (vendor-faithful).
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
        // Resend: "To include a friendly name, pass the sender as `Name <email@example.com>`".
        $from = Mailbox::format($this->config->senderName, $fromAddress);

        $body = [
            'from' => $from,
            'to' => [$input->to],
            'subject' => $input->subject,
        ];
        if ($input->html !== null) {
            $body['html'] = $input->html;
        }
        if ($input->text !== null) {
            $body['text'] = $input->text;
        }
        if ($input->cc !== null && $input->cc !== []) {
            $body['cc'] = $input->cc;
        }
        if ($input->bcc !== null && $input->bcc !== []) {
            $body['bcc'] = $input->bcc;
        }
        if ($input->replyTo !== null) {
            $body['reply_to'] = [$input->replyTo];
        }
        if ($input->headers !== null && $input->headers !== []) {
            $body['headers'] = $input->headers;
        }
        if ($input->tags !== null && $input->tags !== []) {
            $tags = [];
            foreach ($input->tags as $t) {
                // Resend tag name/value: "It can only contain ASCII letters
                // (a–z, A–Z), numbers (0–9), underscores (_), or dashes (-). It
                // can contain no more than 256 characters." Checked here so the
                // caller learns which tag is wrong instead of a bare 422.
                if (preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $t) !== 1) {
                    throw new ConnectorError(
                        providerCode: ProviderCode::InvalidRequest,
                        providerMessage: "Resend: tag '{$t}' may only contain ASCII letters, digits, '_' or '-' (max 256)",
                        statusCode: 400,
                    );
                }
                $tags[] = ['name' => 'tag', 'value' => $t];
            }
            $body['tags'] = $tags;
        }
        if ($input->attachments !== null && $input->attachments !== []) {
            $body['attachments'] = $this->mapAttachments($input->attachments);
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
                'filename' => $a->filename,
                'content' => base64_encode($this->normalizeAttachmentContent($a)),
            ];
            if ($a->contentType !== null && $a->contentType !== '') {
                $entry['content_type'] = $a->contentType;
            }
            // Resend: "You can embed images using the content id parameter for
            // the attachment … `<img src="cid:...">`".
            if ($a->contentId !== null && $a->contentId !== '') {
                $entry['content_id'] = $a->contentId;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return array<string,string>
     */
    private function buildHeaders(EmailSendInput $input): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->config->apiKey,
        ];
        if ($input instanceof ResendNarrowedInput && $input->idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $input->idempotencyKey;
        }

        return $headers;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $vendorMessage = 'Resend HTTP ' . $statusCode;
        if (is_array($body) && isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            $vendorMessage = $body['message'];
        }

        $providerCode = $this->classifyError($statusCode, $body, $vendorMessage);

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

    /**
     * @param array<string,mixed>|null $body
     */
    private function classifyError(int $statusCode, ?array $body, string $message): ProviderCode
    {
        $name = '';
        if (is_array($body) && isset($body['name']) && is_string($body['name'])) {
            $name = $body['name'];
        }

        // HTTP 401/403 is the authoritative auth signal and must win over the
        // name-layer: Resend returns 401 with name `validation_error` for a
        // bad/missing key, which would otherwise be misread as invalid_request.
        if ($statusCode === 401 || $statusCode === 403) {
            return ProviderCode::AuthFailed;
        }

        // Name-layer next when present (more specific than the remaining HTTP-layer).
        switch ($name) {
            case 'missing_api_key':
            case 'invalid_api_key':
            case 'restricted_api_key':
                return ProviderCode::AuthFailed;
            case 'rate_limit_exceeded':
                return ProviderCode::RateLimited;
            case 'application_error':
            case 'internal_server_error':
                return ProviderCode::ProviderUnavailable;
            case 'validation_error':
                return $this->messageMentionsRecipient($message)
                    ? ProviderCode::InvalidRecipient
                    : ProviderCode::InvalidRequest;
        }

        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode >= 500) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($statusCode === 400 || $statusCode === 422) {
            return $this->messageMentionsRecipient($message)
                ? ProviderCode::InvalidRecipient
                : ProviderCode::InvalidRequest;
        }
        if ($statusCode === 413) {
            return ProviderCode::InvalidRequest;
        }

        return ProviderCode::Unknown;
    }

    private function messageMentionsRecipient(string $message): bool
    {
        return preg_match('/recipient|email address/i', $message) === 1;
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
