<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Scaleway;

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
 * Scaleway Transactional Email (TEM) connector — EU-sovereign. POSTs snake_case
 * JSON to the region-scoped path
 * `https://api.scaleway.com/transactional-email/v1alpha1/regions/{region}/emails`
 * with the `X-Auth-Token: <secretKey>` auth header.
 *
 * Mirrors TS (`notifications-ts/src/providers/scaleway/scaleway.connector.ts`).
 * Structurally close to SparkPost (region + nested `from`/`to` address objects
 * + snake_case wire) but differs in three ways handled here:
 *   1. Region is part of the URL PATH (not a host swap).
 *   2. Auth is `X-Auth-Token` (raw secret key), not `Authorization`.
 *   3. `project_id` is MANDATORY and comes from config.
 *
 * This connector has no Novu-compatible `sendMessage()` surface — only `.send()`.
 *
 * Casing stays local: snake_case wire keys are written directly in
 * the array literals; no shared casing transform. PHP `_passthrough` consumers
 * pre-shape snake_case keys at the call site (no `CasingTransform` utility in
 * the bootstrap — same documented constraint as PostmarkEmailConnector).
 *
 * Graceful degradation per the ≥90%-of-providers baseline rule:
 *   - `tags` — no Scaleway field at v1.0; silently dropped.
 *   - attachment `contentId` — no inline-image field at v1.0; silently dropped.
 *   - `replyTo` — no `reply_to` field; emitted as a `Reply-To` entry in
 *     `additional_headers`.
 */
final class ScalewayEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    public readonly string $id;

    private const API_BASE = 'https://api.scaleway.com/transactional-email/v1alpha1';
    private const DEFAULT_ATTACHMENT_MIME = 'application/octet-stream';

    public function __construct(
        private readonly ScalewayConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'scaleway';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(EmailSendInput $input): EmailSendResult
    {
        $body = $this->buildBody($input);
        $headers = [
            'X-Auth-Token' => $this->config->secretKey,
            'Accept'       => 'application/json',
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

        // Scaleway accepts for delivery asynchronously (`status: "new"` = "not
        // yet processed") → Queued (vendor-faithful). A 2xx with an empty
        // or missing `emails` array is still a success — we do not synthesize
        // an error.
        $messageId = $this->extractMessageId($parsed);

        return new EmailSendResult(
            success: true,
            status: EmailStatus::Queued,
            providerMessageId: $messageId,
            raw: $parsed ?? [],
        );
    }

    private function resolveEndpoint(): string
    {
        $region = ($this->config->region ?? ScalewayRegion::FrPar)->value;

        return self::API_BASE . '/regions/' . $region . '/emails';
    }

    /**
     * @return array<string,mixed>
     */
    private function buildBody(EmailSendInput $input): array
    {
        $fromAddress = $input->from !== '' ? $input->from : $this->config->from;
        $from = ['email' => $fromAddress];
        if ($this->config->senderName !== null) {
            $from['name'] = $this->config->senderName;
        }

        $body = [
            'from'       => $from,
            'to'         => [['email' => $input->to]],
            'subject'    => $input->subject,
            'project_id' => $this->config->projectId,
        ];

        if ($input->cc !== null && $input->cc !== []) {
            $body['cc'] = self::toAddressArray($input->cc);
        }
        if ($input->bcc !== null && $input->bcc !== []) {
            $body['bcc'] = self::toAddressArray($input->bcc);
        }
        if ($input->text !== null) {
            $body['text'] = $input->text;
        }
        if ($input->html !== null) {
            $body['html'] = $input->html;
        }

        // additional_headers: Record<string,string> → [{key,value}]. replyTo
        // has no first-class Scaleway field — fold it in as `Reply-To`.
        $additionalHeaders = [];
        if ($input->replyTo !== null) {
            $additionalHeaders[] = ['key' => 'Reply-To', 'value' => $input->replyTo];
        }
        if ($input->headers !== null) {
            foreach ($input->headers as $key => $value) {
                $additionalHeaders[] = ['key' => $key, 'value' => $value];
            }
        }
        if ($additionalHeaders !== []) {
            $body['additional_headers'] = $additionalHeaders;
        }

        if ($input->attachments !== null && $input->attachments !== []) {
            $body['attachments'] = $this->buildAttachments($input->attachments);
        }

        // NB: `tags` has no Scaleway field at v1.0 — silently dropped.

        return $body;
    }

    /**
     * @param list<string> $emails
     *
     * @return list<array{email:string}>
     */
    private static function toAddressArray(array $emails): array
    {
        return array_map(static fn(string $email): array => ['email' => $email], $emails);
    }

    /**
     * @param list<EmailAttachment> $attachments
     *
     * @return list<array{name:string,type:string,content:string}>
     */
    private function buildAttachments(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $a) {
            // Scaleway requires a `type` (mime) on every attachment.
            $out[] = [
                'name'    => $a->filename,
                'type'    => ($a->contentType !== null && $a->contentType !== '')
                    ? $a->contentType
                    : self::DEFAULT_ATTACHMENT_MIME,
                'content' => base64_encode($this->normalizeAttachmentContent($a)),
            ];
            // NB: `contentId` (inline images) has no Scaleway field at v1.0 —
            // silently dropped per the ≥90%-of-providers baseline rule.
        }

        return $out;
    }

    /**
     * @param array<string,mixed>|null $parsed
     */
    private function extractMessageId(?array $parsed): ?string
    {
        if (!is_array($parsed) || !isset($parsed['emails']) || !is_array($parsed['emails'])) {
            return null;
        }
        $first = $parsed['emails'][0] ?? null;
        if (!is_array($first)) {
            return null;
        }
        if (isset($first['message_id']) && is_string($first['message_id'])) {
            return $first['message_id'];
        }
        if (isset($first['id']) && is_string($first['id'])) {
            return $first['id'];
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $vendorMessage = $this->extractErrorMessage($body) ?? ('Scaleway HTTP ' . $statusCode);

        $providerCode = $this->classifyError($statusCode);

        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $providerMessage = $parsedSeconds !== null
            ? "{$vendorMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $vendorMessage;

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            cause: [
                'raw'               => $body,
                'retryAfter'        => $retryAfter !== '' ? $retryAfter : null,
                'retryAfterSeconds' => $parsedSeconds,
            ],
            statusCode: $statusCode,
        );
    }

    /**
     * Scaleway returns `{ message, type, fields }` on most validation failures;
     * some routes return `{ errors: [...] }`. Read both defensively.
     *
     * @param array<string,mixed>|null $body
     */
    private function extractErrorMessage(?array $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }
        if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            return $body['message'];
        }
        if (
            isset($body['errors'][0]['message'])
            && is_string($body['errors'][0]['message'])
            && $body['errors'][0]['message'] !== ''
        ) {
            return $body['errors'][0]['message'];
        }

        return null;
    }

    private function classifyError(int $statusCode): ProviderCode
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
        // 400 (validation), 404 (bad region/project route), 422 (if returned).
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
