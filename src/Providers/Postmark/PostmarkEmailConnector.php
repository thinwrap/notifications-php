<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Postmark;

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
 * Postmark email connector — POSTs `https://api.postmarkapp.com/email` with
 * the idiosyncratic `X-Postmark-Server-Token` auth header and PascalCase JSON
 * wire body.
 *
 * Mirrors the TS connector. Casing locality: PascalCase wire keys
 * (`From`, `To`, `HtmlBody`, `MessageStream`, …) written directly in the array
 * literal; no shared casing transform.
 *
 * Postmark exposes two distinct error paths:
 *   * 200 OK with `ErrorCode > 0` → soft-reject, returned as
 *     `EmailSendResult(success: false, status: 'rejected', …)` by design.
 *   * 4xx/5xx → thrown `ConnectorError`.
 *
 * Single-tag normalization — Thinwrap baseline carries `tags: string[]` (≥90%
 * rule) but Postmark caps at one. The connector takes the first tag and silently
 * drops the rest per the ≥90%-of-providers baseline rule graceful
 * degradation.
 *
 * NOTE: the TS connector calls for a `CasingTransform::toPascal` on
 * `_passthrough.body` before merge. That utility is not present in the bootstrap
 * (no `src/Util/CasingTransform.php`); PHP-idiom consumers writing `_passthrough`
 * are expected to pre-shape PascalCase keys at the call site. Add the transform
 * here if/when the utility lands. Documented in story dev-notes.
 */
final class PostmarkEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://api.postmarkapp.com/email';

    public function __construct(
        private readonly PostmarkConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'postmark';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(EmailSendInput $input): EmailSendResult
    {
        $body = $this->buildBody($input);
        $headers = [
            'X-Postmark-Server-Token' => $this->config->serverToken,
            'Accept' => 'application/json',
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

        // 2xx soft-reject branch — Postmark sometimes 200's with ErrorCode>0.
        $errorCode = is_array($parsed) && isset($parsed['ErrorCode']) && is_int($parsed['ErrorCode'])
            ? $parsed['ErrorCode']
            : 0;
        $messageId = is_array($parsed) && isset($parsed['MessageID']) && is_string($parsed['MessageID'])
            ? $parsed['MessageID']
            : null;

        if ($errorCode !== 0) {
            return new EmailSendResult(
                success: false,
                status: EmailStatus::Rejected,
                providerMessageId: $messageId,
                raw: $parsed,
            );
        }

        // Postmark accepts and sends synchronously, returning the MessageID →
        // `Sent` (vendor-faithful). A 2xx body with ErrorCode>0 is the
        // soft-reject branch above → `Rejected`.
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
        $resolvedFrom = $input->from !== '' ? $input->from : $this->config->from;
        $fromAddress = $this->config->senderName !== null
            ? "{$this->config->senderName} <{$resolvedFrom}>"
            : $resolvedFrom;

        $body = [
            'From' => $fromAddress,
            'To' => $input->to,
            'Subject' => $input->subject,
        ];
        if ($input->cc !== null && $input->cc !== []) {
            $body['Cc'] = implode(', ', $input->cc);
        }
        if ($input->bcc !== null && $input->bcc !== []) {
            $body['Bcc'] = implode(', ', $input->bcc);
        }
        if ($input->html !== null) {
            $body['HtmlBody'] = $input->html;
        }
        if ($input->text !== null) {
            $body['TextBody'] = $input->text;
        }
        if ($input->replyTo !== null) {
            $body['ReplyTo'] = $input->replyTo;
        }
        if ($input->headers !== null && $input->headers !== []) {
            $body['Headers'] = array_map(
                static fn(string $name, string $value): array => ['Name' => $name, 'Value' => $value],
                array_keys($input->headers),
                array_values($input->headers),
            );
        }
        if ($input->tags !== null && $input->tags !== []) {
            $body['Tag'] = $input->tags[0];
        }
        if ($this->config->messageStream !== null) {
            $body['MessageStream'] = $this->config->messageStream;
        }
        if ($input->attachments !== null && $input->attachments !== []) {
            $body['Attachments'] = $this->buildAttachments($input->attachments);
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
            $entry = [
                'Name' => $a->filename,
                'Content' => $encoded,
            ];
            if ($a->contentType !== null && $a->contentType !== '') {
                $entry['ContentType'] = $a->contentType;
            }
            if ($a->contentId !== null) {
                $entry['ContentID'] = 'cid:' . $a->contentId;
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
        $errorCode = is_array($body) && isset($body['ErrorCode']) && is_int($body['ErrorCode'])
            ? $body['ErrorCode']
            : null;
        $vendorMessage = is_array($body) && isset($body['Message']) && is_string($body['Message']) && $body['Message'] !== ''
            ? $body['Message']
            : 'Postmark HTTP ' . $statusCode;

        $providerCode = $this->classifyError($statusCode, $errorCode);

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

    private function classifyError(int $statusCode, ?int $errorCode): ProviderCode
    {
        // Postmark ErrorCode takes precedence over HTTP status when both present.
        if ($errorCode !== null) {
            switch ($errorCode) {
                case 10:    // BadOrMissingApiToken
                case 405:   // AccountInactive / SenderSignatureNotConfirmed
                    return ProviderCode::AuthFailed;
                case 300:   // InactiveRecipient
                case 406:   // RecipientUnsubscribed
                case 411:   // RecipientAddressNull
                case 412:   // RecipientNotInRecipientList
                    return ProviderCode::InvalidRecipient;
                case 422:   // InvalidJson
                case 1003:  // InvalidEmailRequest
                    return ProviderCode::InvalidRequest;
            }
        }

        if ($statusCode === 401 || $statusCode === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode >= 500) {
            return ProviderCode::ProviderUnavailable;
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
