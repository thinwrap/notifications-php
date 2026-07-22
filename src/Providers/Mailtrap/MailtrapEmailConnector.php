<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailtrap;

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
 * Mailtrap email connector — mirrors the TS connector
 * (`notifications-ts/src/providers/mailtrap/mailtrap.connector.ts`).
 *
 * Two endpoints, selected by `$config->mode`:
 *   - `MailtrapMode::Sandbox`    → `https://sandbox.api.mailtrap.io/api/send/<inboxId>`
 *   - `MailtrapMode::Production` → `https://send.api.mailtrap.io/api/send`
 *
 * Unique among v1.0 PHP connectors in running a synchronous constructor-time
 * validator — misconfigurations surface at `new MailtrapEmailConnector(...)`,
 * not on first `send()`.
 *
 * Snake-case wire keys are written directly in the body literal (casing
 * locality: each connector hand-writes its own wire keys, no shared casing
 * transform); consumers writing `_passthrough.body` are responsible
 * for snake_case at the call site (no PHP-side casing-transform utility ships
 * at v1.0).
 */
final class MailtrapEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    public readonly string $id;

    private const SANDBOX_HOST = 'https://sandbox.api.mailtrap.io/api/send';
    private const PRODUCTION_ENDPOINT = 'https://send.api.mailtrap.io/api/send';

    public function __construct(
        private readonly MailtrapConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'mailtrap';
        parent::__construct($client, $requestFactory, $streamFactory);
        $this->validateConfig();
    }

    public function send(EmailSendInput $input): EmailSendResult
    {
        if ($input->tags !== null && $input->tags !== []) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Mailtrap does not support tags',
                statusCode: null,
            );
        }

        $url = $this->resolveEndpoint();
        $body = $this->buildBody($input);
        $headers = [
            'Authorization' => 'Bearer ' . $this->config->apiToken,
            'Accept'        => 'application/json',
        ];

        $merged = Passthrough::merge(
            ['body' => $body, 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );


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

        $messageId = null;
        if (
            is_array($parsed)
            && isset($parsed['message_ids'])
            && is_array($parsed['message_ids'])
            && isset($parsed['message_ids'][0])
            && is_string($parsed['message_ids'][0])
        ) {
            $messageId = $parsed['message_ids'][0];
        }

        // Mailtrap returns `message_ids` synchronously on the send call → `Sent`
        // (vendor-faithful status mapping).
        return new EmailSendResult(
            success: true,
            status: EmailStatus::Sent,
            providerMessageId: $messageId,
            raw: $parsed ?? [],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildBody(EmailSendInput $input): array
    {
        $fromAddress = $input->from !== '' ? $input->from : (string) $this->config->from;
        $fromObject = $this->config->senderName !== null
            ? ['email' => $fromAddress, 'name' => $this->config->senderName]
            : ['email' => $fromAddress];

        $body = [
            'from'    => $fromObject,
            'to'      => [['email' => $input->to]],
            'subject' => $input->subject,
        ];

        if ($input->cc !== null && $input->cc !== []) {
            $body['cc'] = array_map(static fn(string $e): array => ['email' => $e], $input->cc);
        }
        if ($input->bcc !== null && $input->bcc !== []) {
            $body['bcc'] = array_map(static fn(string $e): array => ['email' => $e], $input->bcc);
        }
        if ($input->replyTo !== null) {
            $body['reply_to'] = ['email' => $input->replyTo];
        }
        if ($input->html !== null) {
            $body['html'] = $input->html;
        }
        if ($input->text !== null) {
            $body['text'] = $input->text;
        }
        if ($input->headers !== null && $input->headers !== []) {
            // Mailtrap accepts a flat Record directly (no array reshape needed).
            $body['headers'] = $input->headers;
        }
        if ($input->attachments !== null && $input->attachments !== []) {
            $body['attachments'] = $this->buildAttachments($input->attachments);
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
                'filename' => $a->filename,
                'content'  => $encoded,
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

    private function resolveEndpoint(): string
    {
        if ($this->config->mode === MailtrapMode::Sandbox) {
            // validateConfig() guarantees inboxId is set when mode is Sandbox.
            return self::SANDBOX_HOST . '/' . rawurlencode((string) $this->config->inboxId);
        }
        return self::PRODUCTION_ENDPOINT;
    }

    /**
     * Synchronous constructor-time validator (story 3.14 AC 7).
     */
    private function validateConfig(): void
    {
        if ($this->config->mode === MailtrapMode::Sandbox && $this->config->inboxId === null) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Mailtrap sandbox mode requires inboxId in config',
                statusCode: null,
            );
        }
        if ($this->config->mode === MailtrapMode::Production && $this->config->inboxId !== null) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Mailtrap production mode forbids inboxId in config (it is sandbox-only)',
                statusCode: null,
            );
        }
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $errors = is_array($body) && isset($body['errors']) && is_array($body['errors'])
            ? array_values(array_filter($body['errors'], 'is_string'))
            : [];
        $vendorMessage = $errors !== [] ? implode('; ', $errors) : 'Mailtrap HTTP ' . $statusCode;

        $providerCode = $this->classifyError($statusCode, $errors);

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

    /**
     * @param list<string> $errors
     */
    private function classifyError(int $statusCode, array $errors): ProviderCode
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

        $hasRecipientPhrase = false;
        foreach ($errors as $e) {
            if (preg_match('/recipient|email|to |from address/i', $e) === 1) {
                $hasRecipientPhrase = true;
                break;
            }
        }

        if ($statusCode === 422 || $statusCode === 400) {
            return $hasRecipientPhrase ? ProviderCode::InvalidRecipient : ProviderCode::InvalidRequest;
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
