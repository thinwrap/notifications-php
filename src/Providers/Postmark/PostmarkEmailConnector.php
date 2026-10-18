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
use Thinwrap\Notifications\Util\JsonObject;
use Thinwrap\Notifications\Util\Mailbox;
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
 * `_passthrough.body` keys are sent verbatim (no casing transform), so callers
 * write Postmark's PascalCase keys themselves.
 */
final class PostmarkEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://api.postmarkapp.com/email';

    private const TEMPLATE_ENDPOINT = 'https://api.postmarkapp.com/email/withTemplate';

    public function __construct(
        #[\SensitiveParameter]
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

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];

        // Templates are only sent by `POST /email/withTemplate` ("TemplateId:
        // Required if TemplateAlias is not specified"), whose body has no
        // Subject/HtmlBody/TextBody: "TemplateModel … to generate HtmlBody,
        // TextBody, and Subject". Routing on the merged body means a
        // `_passthrough` TemplateId works as well as the typed field.
        $url = self::ENDPOINT;
        $alias = $mergedBody['TemplateAlias'] ?? null;
        if (isset($mergedBody['TemplateId']) || (is_string($alias) && $alias !== '')) {
            $url = self::TEMPLATE_ENDPOINT;
            unset($mergedBody['Subject'], $mergedBody['HtmlBody'], $mergedBody['TextBody']);
            // TemplateModel is a required object on that endpoint; `{}` for a
            // template without variables, including an explicit null or `[]`.
            $model = $mergedBody['TemplateModel'] ?? null;
            $mergedBody['TemplateModel'] = is_array($model) ? JsonObject::of($model) : ($model ?? new \stdClass());
        }
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
        // Postmark: "To include a name, use the format "Full Name <sender@domain.com>"",
        // and a name with punctuation must be quoted (`"Joe Receiver, Jr." <…>`).
        $fromAddress = Mailbox::format($this->config->senderName, $resolvedFrom);

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
        if ($input instanceof PostmarkNarrowedInput) {
            $body = $this->applyNarrowedFields($input, $body);
        }

        return $body;
    }

    /**
     * Postmark wire names (POST /email and /email/withTemplate): `MessageStream`
     * (the per-send value beats `config.messageStream`), `Metadata`,
     * `TrackOpens`, `TrackLinks` (`None` / `HtmlAndText` / `HtmlOnly` /
     * `TextOnly`), `TemplateId` (an integer), `TemplateAlias`, `TemplateModel`.
     *
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function applyNarrowedFields(PostmarkNarrowedInput $input, array $body): array
    {
        // Postmark: "TemplateId | integer". A non-numeric id is an alias, as
        // postmark-php treats it; naming a template both ways is ambiguous.
        $templateId = $input->templateId;
        $templateAlias = $input->templateAlias;
        if (is_string($templateId)) {
            if (ctype_digit($templateId)) {
                $templateId = (int) $templateId;
            } elseif ($templateId !== '') {
                if ($templateAlias !== null && $templateAlias !== $templateId) {
                    throw self::invalid('Postmark: set either templateId or templateAlias, not both');
                }
                $templateAlias = $templateId;
                $templateId = null;
            }
        }
        if ($templateId !== null && $templateId !== '' && $templateAlias !== null) {
            throw self::invalid('Postmark: set either templateId or templateAlias, not both');
        }
        // TrackLinks is a case-sensitive enum.
        if ($input->trackLinks !== null && !in_array($input->trackLinks, ['None', 'HtmlAndText', 'HtmlOnly', 'TextOnly'], true)) {
            throw self::invalid('Postmark: trackLinks must be None, HtmlAndText, HtmlOnly or TextOnly');
        }
        $fields = [
            'MessageStream' => $input->messageStream,
            'TemplateId' => $templateId,
            'TemplateAlias' => $templateAlias,
            'TemplateModel' => $input->templateModel,
            'Metadata' => $input->metadata !== null ? JsonObject::of($input->metadata) : null,
            'TrackOpens' => $input->trackOpens,
            'TrackLinks' => $input->trackLinks,
        ];
        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
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

        $providerCode = $this->classifyError($statusCode, $errorCode, $vendorMessage);

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
     * Postmark ErrorCode takes precedence over HTTP status when both present;
     * the texts quoted below are Postmark's API error-code table.
     */
    private function classifyError(int $statusCode, ?int $errorCode, string $vendorMessage): ProviderCode
    {
        if ($errorCode !== null) {
            switch ($errorCode) {
                case 10:    // "Request does not contain a valid Server or Account token"
                case 412:   // "While your account is pending approval, all recipient addresses must share the same domain as the From address."
                case 413:   // "This account is not approved to send email."
                case 422:   // "Invalid Server or Account."
                case 1480:  // "You are not authorized to send emails from your current IP address"
                case 405:   // no longer documented; kept as an alias
                    return ProviderCode::AuthFailed;
                case 406:   // "Inactive recipient."
                    return ProviderCode::InvalidRecipient;
                case 300:
                    // "Send validation" covers zero recipients, an invalid
                    // address, a missing body and the 50-recipient limit. Only a
                    // bad To/Cc/Bcc address (e.g. "Error parsing 'To': Illegal
                    // email address") is the recipient's fault; a 'From' or
                    // 'Reply-To' address and "Zero recipients specified" are
                    // the request's.
                    return self::isRecipientAddressError($vendorMessage)
                        ? ProviderCode::InvalidRecipient
                        : ProviderCode::InvalidRequest;
                case 402:   // "Invalid JSON."
                case 403:   // "Invalid request field(s)."
                case 410:   // "You may only send up to 500 messages in a single batched request."
                case 411:   // "Attachment file type not allowed."
                case 1101:  // neither TemplateId nor TemplateAlias, or the template was not found
                case 1235:  // "The stream provided does not exist on this server."
                case 1236:  // "Sending is not supported for this stream type."
                case 1003:  // no longer documented; kept as an alias
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

    private static function invalid(string $message): ConnectorError
    {
        return new ConnectorError(providerCode: ProviderCode::InvalidRequest, providerMessage: $message, statusCode: 400);
    }

    /**
     * Postmark names the field in quotes ("Invalid 'To' address", "Error
     * parsing 'Cc': …"). Matching the quoted name — the same rule as the ts and
     * go connectors — keeps 'Reply-To' and 'From' out, and is not fooled by an
     * address that merely contains "from" or "sender".
     */
    private static function isRecipientAddressError(string $message): bool
    {
        return preg_match("/'(?:to|cc|bcc)'/i", $message) === 1;
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
