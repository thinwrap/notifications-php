<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mailgun;

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
 * Mailgun email connector — POSTs `/v3/{domain}/messages` against the US or
 * EU regional cluster with `application/x-www-form-urlencoded` body and Basic
 * auth (`api:{apiKey}`).
 *
 * Mirrors the TS connector. Casing locality (casing-locality): lower-case keys and
 * `h:` / `o:` / `v:` / `t:` prefixes written directly in the form array; no
 * shared transform.
 *
 * Multi-value `o:tag` is encoded as repeated form keys via `BaseConnector::sendPostForm`'s
 * array-valued field support (same helper Twilio's `MediaUrl` uses).
 *
 * Attachments are sent as `multipart/form-data` via
 * `BaseConnector::sendPostMultipart`: each attachment becomes a
 * `name="attachment"` part (or `name="inline"` when `contentId` is set, per
 * Mailgun's cid pattern). When attachments are absent the body is
 * `application/x-www-form-urlencoded`. `o:tag` is emitted in BOTH paths,
 * matching the TS connector `buildMultipartBody`.
 */
final class MailgunEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    public readonly string $id;

    private const BASE_HOSTS = [
        'us' => 'https://api.mailgun.net',
        'eu' => 'https://api.eu.mailgun.net',
    ];

    private const TAG_CAP = 3;

    public function __construct(
        #[\SensitiveParameter]
        private readonly MailgunConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'mailgun';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(EmailSendInput $input): EmailSendResult
    {
        $regionKey = $this->config->region->value ?? 'us';
        $base = self::BASE_HOSTS[$regionKey];
        $url = $base . '/v3/' . rawurlencode($this->config->domain) . '/messages';

        $form = $this->buildForm($input);
        $headers = [
            'Authorization' => 'Basic ' . base64_encode("api:{$this->config->apiKey}"),
        ];

        // Mailgun's form body has special multi-value handling for `o:tag`,
        // so we don't run it through Passthrough::merge's deep-merge. Apply
        // passthrough.body keys (assumed form-compatible scalars/lists) on top
        // of the form; headers + query merge via the helper.
        $merged = Passthrough::merge(
            ['body' => [], 'headers' => $headers, 'query' => []],
            $input->_passthrough,
        );
        /** @var array<string,mixed> $passthroughBody */
        $passthroughBody = $merged['body'];
        /** @var array<string,string> $finalHeaders */
        $finalHeaders = $merged['headers'];

        // Passthrough wins over every connector-built field. A list becomes
        // repeated fields (Mailgun: "You can post multiple `attachment`
        // values", `o:tag` repeats); anything else structured — e.g.
        // `t:variables`, "A valid JSON-encoded dictionary" — is sent as
        // compact JSON rather than dropped.
        foreach ($passthroughBody as $k => $v) {
            if (is_array($v) && !self::isListOfScalars($v)) {
                $v = self::encodeJson($v, (string) $k);
            }
            /** @var scalar|list<scalar>|null $v */
            self::setField($form, (string) $k, $v);
        }
        // A template's stored subject is used only when none is sent (Mailgun:
        // subject "will override [the template's] if provided"), so an empty
        // one is dropped rather than sent as `subject=`.
        if (isset($form['template']) && ($form['subject'] ?? null) === '') {
            unset($form['subject']);
        }

        // Capped, ordered tag list — emitted in BOTH transport paths.
        $tags = ($input->tags !== null && $input->tags !== [])
            ? array_slice($input->tags, 0, self::TAG_CAP)
            : [];

        if ($input->attachments !== null && $input->attachments !== []) {
            $parts = $this->buildMultipartParts($form, $tags, $input->attachments);
            $response = $this->sendPostMultipart($url, $parts, $finalHeaders);
        } else {
            if ($tags !== []) {
                $existing = $form['o:tag'] ?? null;
                $form['o:tag'] = array_merge(
                    is_array($existing) ? $existing : ($existing !== null ? [$existing] : []),
                    $tags,
                );
            }
            $response = $this->sendPostForm($url, $form, $finalHeaders);
        }

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

        // Mailgun queues messages for asynchronous delivery (the response is an
        // accept-for-delivery acknowledgement) → `Queued` (vendor-faithful).
        return new EmailSendResult(
            success: true,
            status: EmailStatus::Queued,
            providerMessageId: $messageId,
            raw: $parsed,
        );
    }

    /**
     * Set one form field. Header fields (`h:` prefix) are matched
     * case-insensitively, because header names are, so a later `h:reply-to`
     * replaces an earlier `h:Reply-To` instead of emitting a second Reply-To.
     *
     * @param array<string,scalar|list<scalar>|null> $form
     * @param scalar|list<scalar>|null $value
     */
    private static function setField(array &$form, string $key, mixed $value): void
    {
        if (strncasecmp($key, 'h:', 2) === 0) {
            foreach (array_keys($form) as $existing) {
                if (strcasecmp((string) $existing, $key) === 0) {
                    unset($form[$existing]);
                }
            }
        }
        $form[$key] = $value;
    }

    /**
     * @param array<int|string,mixed> $value
     */
    private static function encodeJson(array $value, string $field): string
    {
        // "A valid JSON-encoded dictionary": an empty map is `{}`, not `[]`.
        if ($value === []) {
            return '{}';
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Mailgun: field '{$field}' could not be JSON-encoded",
                cause: ['raw' => ['exception' => $e::class], 'retryAfter' => null],
                statusCode: 400,
            );
        }
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
     * @return array<string,scalar|list<scalar>|null>
     */
    private function buildForm(EmailSendInput $input): array
    {
        $from = $input->from !== '' ? $input->from : (string) $this->config->from;
        // Mailgun: "Can include a friendly name using the format "Friendly Name <email@domain.com>"".
        $from = Mailbox::format($this->config->senderName, $from);

        $form = [
            'from' => $from,
            'to' => $input->to,
            'subject' => $input->subject,
        ];
        if ($input->html !== null) {
            $form['html'] = $input->html;
        }
        if ($input->text !== null) {
            $form['text'] = $input->text;
        }
        if ($input->cc !== null && $input->cc !== []) {
            $form['cc'] = implode(', ', $input->cc);
        }
        if ($input->bcc !== null && $input->bcc !== []) {
            $form['bcc'] = implode(', ', $input->bcc);
        }
        // Precedence, low to high: `headers`, then the typed `replyTo`, then
        // `_passthrough.body` (applied in send()).
        if ($input->headers !== null) {
            foreach ($input->headers as $name => $value) {
                self::setField($form, 'h:' . $name, $value);
            }
        }
        if ($input->replyTo !== null) {
            self::setField($form, 'h:Reply-To', $input->replyTo);
        }
        if ($input instanceof MailgunNarrowedInput) {
            $form = $this->applyNarrowedFields($input, $form);
        }
        // Tags are NOT emitted here — they are appended per transport path in
        // send() so `o:tag` is present in both the form-urlencoded and
        // multipart bodies.

        return $form;
    }

    /**
     * Mailgun `POST /v3/{domain}/messages` wire names. The tracking and DKIM
     * options accept `yes`/`no`; `o:testmode` and `t:text` document `yes` as
     * their only value, so `false` omits the field. `t:variables` is "A valid
     * JSON-encoded dictionary"; each custom variable is its own `v:<name>`
     * field.
     *
     * @param array<string,scalar|list<scalar>|null> $form
     *
     * @return array<string,scalar|list<scalar>|null>
     */
    private function applyNarrowedFields(MailgunNarrowedInput $input, array $form): array
    {
        $yesNo = [
            'o:tracking' => $input->oTracking,
            'o:tracking-clicks' => $input->oTrackingClicks,
            'o:tracking-opens' => $input->oTrackingOpens,
            'o:dkim' => $input->oDkim,
        ];
        foreach ($yesNo as $key => $value) {
            if (is_bool($value)) {
                $form[$key] = $value ? 'yes' : 'no';
            } elseif ($value !== null) {
                // Only o:tracking and o:tracking-clicks document `htmlonly`.
                if ($value !== 'htmlonly' || ($key !== 'o:tracking' && $key !== 'o:tracking-clicks')) {
                    throw new ConnectorError(
                        providerCode: ProviderCode::InvalidRequest,
                        providerMessage: "Mailgun: {$key} accepts true, false or 'htmlonly'",
                        statusCode: 400,
                    );
                }
                $form[$key] = $value;
            }
        }
        if ($input->oTestmode === true) {
            $form['o:testmode'] = 'yes';
        }
        if ($input->oDeliverytime !== null) {
            $form['o:deliverytime'] = $input->oDeliverytime;
        }
        foreach ($input->vCustomVars ?? [] as $name => $value) {
            // A structured value would be flattened by form encoding and lose
            // its keys; send it as JSON, as the passthrough path does.
            $form['v:' . $name] = is_array($value) ? self::encodeJson($value, 'v:' . $name) : (is_scalar($value) ? $value : null);
        }
        if ($input->template !== null) {
            $form['template'] = $input->template;
        }
        if ($input->tVariables !== null) {
            $form['t:variables'] = self::encodeJson($input->tVariables, 't:variables');
        }
        if ($input->tVersion !== null) {
            $form['t:version'] = $input->tVersion;
        }
        if ($input->tText !== null) {
            $form['t:text'] = $input->tText;
        }

        return $form;
    }

    /**
     * Build the multipart part list for the attachments path. Mirrors the TS
     * `buildMultipartBody` ordering: scalar form fields first, then repeated
     * `o:tag` parts, then one part per attachment (`name="attachment"`,
     * or `name="inline"` when `contentId` is set). Array-valued form fields
     * (e.g. passthrough lists) are flattened into repeated parts. Attachment
     * content is resolved via the shared `normalizeAttachmentContent()` (a documented design decision);
     * CRLF/quote sanitization of part names/filenames/types happens in
     * `BaseConnector::sendPostMultipart`.
     *
     * @param array<string,scalar|array<array-key,scalar>|null> $form
     * @param list<string>                                       $tags
     * @param list<EmailAttachment>                              $attachments
     *
     * @return list<array{name:string,contents:string,filename?:string,contentType?:string}>
     */
    private function buildMultipartParts(array $form, array $tags, array $attachments): array
    {
        $parts = [];

        foreach ($form as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $sub) {
                    $parts[] = ['name' => (string) $name, 'contents' => self::stringifyScalar($sub)];
                }
            } else {
                $parts[] = ['name' => (string) $name, 'contents' => self::stringifyScalar($value)];
            }
        }

        foreach ($tags as $tag) {
            $parts[] = ['name' => 'o:tag', 'contents' => $tag];
        }

        foreach ($attachments as $attachment) {
            $isInline = $attachment->contentId !== null && $attachment->contentId !== '';
            // Mailgun "assigns content-id to each image passed via the inline
            // API parameter" from the part's filename ("the actual filename
            // must correctly match what's used in the img cid tag"), so an
            // inline part is named after the caller's contentId.
            $part = [
                'name' => $isInline ? 'inline' : 'attachment',
                'filename' => $isInline ? (string) $attachment->contentId : $attachment->filename,
                'contents' => $this->normalizeAttachmentContent($attachment),
            ];
            if ($attachment->contentType !== null && $attachment->contentType !== '') {
                $part['contentType'] = $attachment->contentType;
            } else {
                $part['contentType'] = 'application/octet-stream';
            }
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * @param scalar $value
     */
    private static function stringifyScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $vendorMessage = 'Mailgun HTTP ' . $statusCode;
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
                'raw' => $body,
                'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
                'retryAfterSeconds' => $parsedSeconds,
            ],
            statusCode: $statusCode,
        );
    }

    private function classifyError(int $statusCode, string $message): ProviderCode
    {
        // 403: "Valid credentials but access denied. Ensure you have
        // permissions for the requested resource." (Mailgun API overview)
        if ($statusCode === 401 || $statusCode === 402 || $statusCode === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($statusCode === 400) {
            return preg_match('/not a valid email|invalid recipient|invalid address/i', $message) === 1
                ? ProviderCode::InvalidRecipient
                : ProviderCode::InvalidRequest;
        }
        if ($statusCode === 404 || $statusCode === 413) {
            return ProviderCode::InvalidRequest;
        }
        if ($statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode >= 500) {
            return ProviderCode::ProviderUnavailable;
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
