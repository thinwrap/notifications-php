<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailersend;

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
use Thinwrap\Notifications\Util\Passthrough;

/**
 * MailerSend email connector — POSTs `https://api.mailersend.com/v1/email`
 * with Bearer auth and snake_case JSON wire body.
 *
 * Mirrors the TS connector. Casing locality (casing-locality): snake_case wire keys
 * written directly in the array literal; no shared casing transform.
 *
 * 2xx is 202 Accepted with empty body; `providerMessageId` is sourced from the
 * `X-Message-Id` response header (PSR-7 `getHeaderLine` returns `''` when
 * absent → coerced to `null`). A 202 can still carry a `warnings` body when
 * recipients are on a suppression list: `ALL_SUPPRESSED` means the email was
 * not sent (and no `X-Message-Id` is issued), `SOME_SUPPRESSED` means it went
 * to the remaining recipients.
 *
 * Tag cap: MailerSend allows up to 5 tags; the connector truncates `tags` to
 * first 5 silently per the ≥90%-of-providers baseline rule graceful
 * degradation.
 *
 * `reply_to` is a single object (`{email, name}`), as MailerSend documents it,
 * not an array.
 */
final class MailersendEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    public readonly string $id;

    private const ENDPOINT = 'https://api.mailersend.com/v1/email';

    private const TAG_CAP = 5;

    public function __construct(
        #[\SensitiveParameter]
        private readonly MailersendConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'mailersend';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(EmailSendInput $input): EmailSendResult
    {
        $body = $this->buildBody($input);
        $headers = [
            'Authorization' => 'Bearer ' . $this->config->apiToken,
            'X-Requested-With' => 'XMLHttpRequest',
        ];

        $merged = Passthrough::merge(
            ['body' => $body, 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );

        $url = self::ENDPOINT;

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        // subject is "Not required if `template_id` is present with default
        // subject set": an empty one would override the template's.
        if (isset($mergedBody['template_id']) && ($mergedBody['subject'] ?? null) === '') {
            unset($mergedBody['subject']);
        }
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];

        $response = $this->sendPostJson($url, $mergedBody, $mergedHeaders);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        $messageId = $response->getHeaderLine('X-Message-Id') ?: null;
        $parsed = $this->decodeJson($response);

        if ($parsed !== null && self::hasWarning($parsed, 'ALL_SUPPRESSED')) {
            $vendorMessage = isset($parsed['message']) && is_string($parsed['message']) && $parsed['message'] !== ''
                ? $parsed['message']
                : 'MailerSend: all recipients are suppressed; email not sent';

            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRecipient,
                providerMessage: $vendorMessage,
                cause: ['raw' => $parsed, 'retryAfter' => null],
                statusCode: $status,
            );
        }

        // MailerSend returns 202 Accepted with the message id in X-Message-Id —
        // an accept-for-delivery acknowledgement → `Queued` (vendor-faithful).
        // Any `SOME_SUPPRESSED` warnings ride along on `raw`.
        return new EmailSendResult(
            success: true,
            status: EmailStatus::Queued,
            providerMessageId: $messageId,
            raw: array_merge(['messageId' => $messageId], $parsed ?? []),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildBody(EmailSendInput $input): array
    {
        $from = ['email' => $input->from];
        if ($this->config->senderName !== null) {
            $from['name'] = $this->config->senderName;
        }

        $body = [
            'from' => $from,
            'to' => [['email' => $input->to]],
            'subject' => $input->subject,
        ];
        if ($input->cc !== null && $input->cc !== []) {
            $body['cc'] = array_map(static fn(string $addr): array => ['email' => $addr], $input->cc);
        }
        if ($input->bcc !== null && $input->bcc !== []) {
            $body['bcc'] = array_map(static fn(string $addr): array => ['email' => $addr], $input->bcc);
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
        if ($input->tags !== null && $input->tags !== []) {
            $body['tags'] = array_slice($input->tags, 0, self::TAG_CAP);
        }
        if ($input->attachments !== null && $input->attachments !== []) {
            $body['attachments'] = $this->mapAttachments($input->attachments);
        }
        if ($input->headers !== null && $input->headers !== []) {
            // MailerSend takes custom headers as a list of `{name, value}` objects.
            $body['headers'] = array_map(
                static fn(string $name, string $value): array => ['name' => $name, 'value' => $value],
                array_keys($input->headers),
                array_values($input->headers),
            );
        }
        if ($input instanceof MailersendNarrowedInput) {
            $body = $this->applyNarrowedFields($input, $body);
        }

        return $body;
    }

    /**
     * MailerSend `POST /v1/email` wire names: `template_id`, `personalization`
     * (`[{email, data}]`), `send_at` (unix seconds, at most 72 h ahead),
     * `precedence_bulk`, and `settings`, which "Can only contain the keys:
     * track_clicks, track_opens and track_content". `variables` is not sent:
     * MailerSend removed simple personalization from the API and its SDKs.
     *
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function applyNarrowedFields(MailersendNarrowedInput $input, array $body): array
    {
        $fields = [
            'template_id' => $input->templateId,
            // Each entry's `data` is an object (`{"var": "value"}`), `{}` when empty.
            'personalization' => $input->personalization !== null
                ? array_map(static function (array $p): array {
                    // MailerSend personalization entries are `{email, data}`;
                    // without an object `data` there is nothing to substitute.
                    if (!isset($p['data']) || !is_array($p['data'])) {
                        throw new ConnectorError(
                            providerCode: ProviderCode::InvalidRequest,
                            providerMessage: 'MailerSend personalization entries need a `data` map',
                            statusCode: 400,
                        );
                    }
                    $p['data'] = JsonObject::of($p['data']);

                    return $p;
                }, $input->personalization)
                : null,
            'send_at' => $input->sendAt,
            'precedence_bulk' => $input->precedenceBulk,
        ];
        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
        }
        if ($input->settings !== null) {
            // MailerSend: settings "Can only contain the keys: track_clicks,
            // track_opens and track_content and a boolean value of true or
            // false". Both our camelCase and MailerSend's own snake_case keys
            // are accepted; anything else is refused rather than silently
            // dropped (a dropped track_clicks:false leaves tracking on).
            $keys = [
                'trackClicks' => 'track_clicks', 'track_clicks' => 'track_clicks',
                'trackOpens' => 'track_opens', 'track_opens' => 'track_opens',
                'trackContent' => 'track_content', 'track_content' => 'track_content',
            ];
            $settings = [];
            foreach ($input->settings as $key => $value) {
                if (!isset($keys[$key]) || !is_bool($value)) {
                    throw new ConnectorError(
                        providerCode: ProviderCode::InvalidRequest,
                        providerMessage: 'MailerSend settings accept only track_clicks, track_opens and track_content with boolean values',
                        statusCode: 400,
                    );
                }
                $settings[$keys[$key]] = $value;
            }
            if ($settings !== []) {
                $body['settings'] = $settings;
            }
        }

        return $body;
    }

    /**
     * @param array<string,mixed> $parsed
     */
    private static function hasWarning(array $parsed, string $type): bool
    {
        $warnings = $parsed['warnings'] ?? null;
        if (!is_array($warnings)) {
            return false;
        }
        foreach ($warnings as $warning) {
            if (is_array($warning) && ($warning['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
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
            // MailerSend attachments are `content`, `filename`, `disposition`
            // and `id` only — there is no content-type field; MailerSend
            // derives the type from the filename.
            if ($a->contentId !== null) {
                $entry['id'] = $a->contentId;
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
        $vendorMessage = 'MailerSend HTTP ' . $statusCode;
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
        if ($statusCode === 401 || $statusCode === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode >= 500) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($statusCode === 422) {
            // Recipient-field detection in `errors`.
            if (is_array($body) && isset($body['errors']) && is_array($body['errors'])) {
                foreach (array_keys($body['errors']) as $field) {
                    if (!is_string($field)) {
                        continue;
                    }
                    if (str_starts_with($field, 'to.') || str_starts_with($field, 'cc.') || str_starts_with($field, 'bcc.') || $field === 'from.email') {
                        return ProviderCode::InvalidRecipient;
                    }
                }
            }
            if (preg_match('/recipient|email address/i', $message) === 1) {
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
