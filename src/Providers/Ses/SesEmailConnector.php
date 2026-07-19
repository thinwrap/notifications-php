<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Ses;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Base\BaseConnector;
use Thinwrap\Notifications\Contract\EmailConnectorInterface;
use Thinwrap\Notifications\DTO\Email\EmailSendInput;
use Thinwrap\Notifications\DTO\Email\EmailSendResult;
use Thinwrap\Notifications\DTO\Email\EmailStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Util\Passthrough;

/**
 * AWS SES v2 SendEmail connector (PHP, hand-rolled Sig V4).
 *
 * POSTs to `https://email.{region}.amazonaws.com/v2/email/outbound-emails`
 * with a hand-rolled AWS Signature Version 4 implementation backed entirely by
 * PHP stdlib (`hash_hmac`, `hash`, `gmdate`) — no `aws/aws-sdk-php` dependency.
 *
 * Per the stateless-wrapper rule, every `.send()` call
 * computes a fresh signature with a fresh `X-Amz-Date` — no signature caching,
 * no signing-key memoization across calls.
 *
 * Sig V4 is intentionally duplicated with the SNS SMS connector
 * per the casing-locality rule — extraction threshold is a third AWS-signed
 * connector.
 */
class SesEmailConnector extends BaseConnector implements EmailConnectorInterface
{
    /**
     * Headers never folded into the Sig V4 signature, even when supplied via
     * `_passthrough.headers` (they still go on the wire, just unsigned — AWS
     * only validates headers listed in `SignedHeaders`). Per the AWS signing
     * guide: "Do not include hop-by-hop headers that are frequently altered
     * during transit" — proxies, load balancers, and the HTTP client itself
     * rewrite these after signing, which would invalidate the signature.
     * `content-length` is additionally client-owned (Guzzle/cURL recompute it
     * from the body). Mirrors `@smithy/signature-v4` ALWAYS_UNSIGNABLE_HEADERS
     * plus `content-length`, byte-parity with the TS sibling. Note
     * `Content-Type` is deliberately NOT here: the same guide requires it
     * signed when present (`aws-sdk-php` skips it; smithy/botocore sign it —
     * we follow the spec text and keep TS↔PHP signatures identical).
     *
     * @var array<string,true>
     */
    private const UNSIGNABLE_HEADERS = [
        'authorization'       => true,
        'cache-control'       => true,
        'connection'          => true,
        'content-length'      => true,
        'expect'              => true,
        'from'                => true,
        'keep-alive'          => true,
        'max-forwards'        => true,
        'pragma'              => true,
        'proxy-authenticate'  => true,
        'proxy-authorization' => true,
        'referer'             => true,
        'te'                  => true,
        'trailer'             => true,
        'transfer-encoding'   => true,
        'upgrade'             => true,
        'user-agent'          => true,
        'x-amzn-trace-id'     => true,
    ];

    public readonly string $id;

    public function __construct(
        private readonly SesConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'ses';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(EmailSendInput $input): EmailSendResult
    {
        $connectorBody = $this->buildSesV2Body($input);
        $connectorHeaders = ['Content-Type' => 'application/json'];

        $merged = Passthrough::merge(
            ['body' => $connectorBody, 'headers' => $connectorHeaders, 'query' => []],
            $input->_passthrough,
        );

        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $this->stripNulls($merged['body']);
        /** @var array<string,string> $mergedHeaders */
        $mergedHeaders = $merged['headers'];
        /** @var array<string,mixed> $mergedQuery */
        $mergedQuery = $merged['query'];

        // A `_passthrough.query` must be folded (sorted) into BOTH the
        // request URL AND the SigV4 canonical query string; otherwise it is
        // either silently dropped or produces a SignatureDoesNotMatch.
        $canonicalQuery = $this->buildCanonicalQuery($mergedQuery);

        $serializedBody = json_encode($mergedBody, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $isoTimestamp = $this->getTimestamp();

        $signedHeaders = $this->signRequest($serializedBody, $mergedHeaders, $isoTimestamp, $canonicalQuery);

        // Signed values win on collision — Host/X-Amz-Date/Authorization are
        // not consumer-overridable. Caller-merged passthrough headers participate
        // in the signature; subsequent AWS-managed headers replace any collisions.
        $finalHeaders = array_merge($mergedHeaders, $signedHeaders);

        $host = $this->host();
        $url = "https://{$host}/v2/email/outbound-emails";
        if ($canonicalQuery !== '') {
            $url .= '?' . $canonicalQuery;
        }

        $response = $this->sendPostJsonRaw($url, $serializedBody, $finalHeaders);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            /** @var array<string,mixed>|null $errBody */
            $errBody = $this->decodeJson($response);
            throw $this->mapVendorError($status, $errBody, $response);
        }

        /** @var array<string,mixed>|null $parsed */
        $parsed = $this->decodeJson($response);
        $messageId = null;
        if (is_array($parsed) && isset($parsed['MessageId']) && is_string($parsed['MessageId'])) {
            $messageId = $parsed['MessageId'];
        }

        // SES v2 SendEmail is a synchronous accept-for-delivery that returns the
        // assigned MessageId in the response → `Sent` (vendor-faithful).
        return new EmailSendResult(
            success: true,
            status: EmailStatus::Sent,
            providerMessageId: $messageId,
            raw: $parsed,
        );
    }

    /**
     * Build the SES v2 SendEmail JSON body from `EmailSendInput`. PascalCase
     * keys are written directly in the array literal per the casing-locality rule.
     * `null` values are stripped after Passthrough merge (SES rejects nulls in
     * nested structures with `ValidationException`).
     *
     * Attachments or custom `headers` force the raw-MIME path (`Content.Raw`,
     * a documented design decision, ported from TS `buildMimeMessageFromInput`); otherwise
     * the simpler `Content.Simple` structure is used. `tags` map to
     * `EmailTags: [{Name, Value}]` and the sender falls back to `config.from`
     * with optional `config.senderName` display-name wrapping.
     *
     * @return array<string,mixed>
     */
    private function buildSesV2Body(EmailSendInput $input): array
    {
        $fromEmailAddress = $this->resolveFrom($input);

        $destination = [
            'ToAddresses' => [$input->to],
            'CcAddresses' => $input->cc,
            'BccAddresses' => $input->bcc,
        ];

        $hasAttachments = $input->attachments !== null && $input->attachments !== [];
        $hasHeaders = $input->headers !== null && $input->headers !== [];

        if ($hasAttachments || $hasHeaders) {
            $mime = $this->buildMimeMessage($input, $fromEmailAddress);
            $content = ['Raw' => ['Data' => base64_encode($mime)]];
        } else {
            $content = [
                'Simple' => [
                    'Subject' => ['Data' => $input->subject, 'Charset' => 'UTF-8'],
                    'Body' => [
                        'Html' => $input->html !== null ? ['Data' => $input->html, 'Charset' => 'UTF-8'] : null,
                        'Text' => $input->text !== null ? ['Data' => $input->text, 'Charset' => 'UTF-8'] : null,
                    ],
                ],
            ];
        }

        $body = [
            'FromEmailAddress' => $fromEmailAddress,
            'Destination' => $destination,
            'ReplyToAddresses' => $input->replyTo !== null ? [$input->replyTo] : null,
            'Content' => $content,
        ];

        $emailTags = $this->liftTags($input->tags);
        if ($emailTags !== []) {
            $body['EmailTags'] = $emailTags;
        }

        return $body;
    }

    /**
     * Resolve the sender: per-message `from` wins, else `config.from`; wrap with
     * `config.senderName` as `Name <addr>` when present.
     */
    private function resolveFrom(EmailSendInput $input): string
    {
        $address = $input->from !== '' ? $input->from : (string) $this->config->from;
        if ($this->config->senderName !== null && $this->config->senderName !== '') {
            return $this->config->senderName . ' <' . $address . '>';
        }

        return $address;
    }

    /**
     * SES expects `EmailTags: [{Name, Value}]`. The base `EmailSendInput.tags`
     * is `list<string>`; each bare tag becomes `{Name: tag, Value: tag}`
     * (a documented design decision, mirrors TS `liftSesTags`).
     *
     * @param list<string>|null $tags
     *
     * @return list<array{Name:string,Value:string}>
     */
    private function liftTags(?array $tags): array
    {
        if ($tags === null || $tags === []) {
            return [];
        }

        return array_map(static fn(string $tag): array => ['Name' => $tag, 'Value' => $tag], $tags);
    }

    /**
     * Build a raw multipart MIME message for SES `Content.Raw.Data` from the
     * base `EmailSendInput` (a documented design decision, ported from TS
     * `buildMimeMessageFromInput`). Header values are CRLF-stripped to prevent
     * header injection; non-ASCII From/Subject are RFC 2047 base64 encoded.
     */
    private function buildMimeMessage(EmailSendInput $input, string $from): string
    {
        $boundary = '----=_Part_' . bin2hex(random_bytes(8));
        $altBoundary = '----=_Alt_' . bin2hex(random_bytes(8));

        $lines = [];
        $lines[] = 'From: ' . self::encodeHeaderValue($from);
        $lines[] = 'To: ' . self::stripCrlf($input->to);
        if ($input->cc !== null && $input->cc !== []) {
            $lines[] = 'Cc: ' . implode(', ', array_map(self::stripCrlf(...), $input->cc));
        }
        if ($input->bcc !== null && $input->bcc !== []) {
            $lines[] = 'Bcc: ' . implode(', ', array_map(self::stripCrlf(...), $input->bcc));
        }
        if ($input->replyTo !== null) {
            $lines[] = 'Reply-To: ' . self::stripCrlf($input->replyTo);
        }
        // Caller-supplied custom headers (CRLF-stripped name + value).
        if ($input->headers !== null) {
            foreach ($input->headers as $name => $value) {
                $lines[] = self::stripCrlf((string) $name) . ': ' . self::stripCrlf($value);
            }
        }
        $lines[] = 'Subject: ' . self::encodeHeaderValue($input->subject);
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $lines[] = '';

        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
        $lines[] = '';

        if ($input->text !== null && $input->text !== '') {
            $lines[] = '--' . $altBoundary;
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';
            // 8bit (not 7bit): the body may carry non-ASCII UTF-8 octets
            // (accents, emoji); declaring 7bit over an 8-bit body is a MIME
            // encoding mismatch on the raw-MIME path.
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            $lines[] = $input->text;
            $lines[] = '';
        }
        if ($input->html !== null && $input->html !== '') {
            $lines[] = '--' . $altBoundary;
            $lines[] = 'Content-Type: text/html; charset=UTF-8';
            // 8bit (not 7bit): see the text/plain part above — the HTML body may
            // contain non-ASCII UTF-8 octets, so declare the encoding truthfully.
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            $lines[] = $input->html;
            $lines[] = '';
        }
        $lines[] = '--' . $altBoundary . '--';
        $lines[] = '';

        foreach ($input->attachments ?? [] as $attachment) {
            $contentType = ($attachment->contentType ?? '') !== ''
                ? (string) $attachment->contentType
                : 'application/octet-stream';
            $filename = self::quoteMimeFilename($attachment->filename);
            // Fold the base64 stream into 76-char lines (RFC 2045 §6.8) so no
            // MIME line exceeds RFC 5322's 998-octet limit; an unwrapped blob
            // >~748 bytes would make SES forward non-conformant MIME. The
            // trailing CRLF chunk_split() appends is trimmed so the payload
            // slots cleanly between the blank line and the next boundary.
            $encoded = rtrim(
                chunk_split(base64_encode($this->normalizeAttachmentContent($attachment)), 76, "\r\n"),
                "\r\n",
            );

            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: ' . self::stripCrlf($contentType) . '; name=' . $filename;
            $lines[] = 'Content-Transfer-Encoding: base64';
            if ($attachment->contentId !== null) {
                $lines[] = 'Content-ID: <' . self::stripCrlf($attachment->contentId) . '>';
                $lines[] = 'Content-Disposition: inline; filename=' . $filename;
            } else {
                $lines[] = 'Content-Disposition: attachment; filename=' . $filename;
            }
            $lines[] = '';
            $lines[] = $encoded;
            $lines[] = '';
        }

        $lines[] = '--' . $boundary . '--';
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    /**
     * Strip CR/LF from a header value to prevent header injection. MIME header
     * field bodies cannot contain bare CR/LF.
     */
    private static function stripCrlf(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }

    /**
     * RFC 2047 base64 encoded-word for non-ASCII header values; ASCII passes
     * through. Always CRLF-stripped (defence in depth).
     */
    private static function encodeHeaderValue(string $value): string
    {
        $stripped = self::stripCrlf($value);
        if (preg_match('/[^\x00-\x7F]/', $stripped) !== 1) {
            return $stripped;
        }

        return '=?UTF-8?B?' . base64_encode($stripped) . '?=';
    }

    /**
     * Quote a MIME filename per RFC 2183; embedded `"`/`\` are backslash-escaped.
     */
    private static function quoteMimeFilename(string $name): string
    {
        $stripped = self::stripCrlf($name);

        return '"' . preg_replace('/(["\\\\])/', '\\\\$1', $stripped) . '"';
    }

    /**
     * Recursively strip `null` values from nested arrays. SES `ValidationException`
     * guard — SES v2 rejects `null` in nested structures.
     *
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function stripNulls(array $body): array
    {
        $out = [];
        foreach ($body as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_array($v)) {
                $cleaned = $this->stripNulls($v);
                if ($cleaned !== []) {
                    $out[$k] = $cleaned;
                }
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Hand-rolled AWS Sig V4. Returns the headers that must attach to the
     * request: Host, X-Amz-Date, Authorization, and (optionally) X-Amz-Security-Token.
     *
     * @param array<string,string> $additionalSignedHeaders Headers beyond Host/X-Amz-Date that must participate in the signature.
     * @param string $canonicalQuery Sorted, encoded canonical query string (no leading `?`); empty when no passthrough query.
     *
     * @return array<string,string>
     */
    private function signRequest(string $serializedJsonBody, array $additionalSignedHeaders, string $isoTimestamp, string $canonicalQuery = ''): array
    {
        $region = $this->config->region;
        $service = 'ses';
        $host = $this->host();
        $dateStamp = substr($isoTimestamp, 0, 8);

        $hashedPayload = hash('sha256', $serializedJsonBody);

        // Canonical headers — Host, X-Amz-Date, X-Amz-Content-Sha256, plus any
        // caller-supplied headers (e.g., Content-Type from connector + any
        // passthrough headers).
        $canonicalHeaders = $additionalSignedHeaders;
        $canonicalHeaders['Host'] = $host;
        $canonicalHeaders['X-Amz-Date'] = $isoTimestamp;
        $canonicalHeaders['X-Amz-Content-Sha256'] = $hashedPayload;
        if ($this->config->sessionToken !== null) {
            $canonicalHeaders['X-Amz-Security-Token'] = $this->config->sessionToken;
        }

        // Lower-case names, trim + collapse internal whitespace runs in values
        // per the Sig V4 canonicalization spec (`aws-sdk-php` does the same
        // via `preg_replace('/\s+/', ' ', ...)`); sort by lower-cased name.
        // Hop-by-hop / client-owned headers are excluded from the signature.
        $normalized = [];
        foreach ($canonicalHeaders as $name => $value) {
            $lowerName = strtolower($name);
            if (isset(self::UNSIGNABLE_HEADERS[$lowerName])) {
                continue;
            }
            $normalized[$lowerName] = (string) preg_replace('/\s+/', ' ', trim((string) $value));
        }
        ksort($normalized);

        $canonicalHeadersString = '';
        foreach ($normalized as $name => $value) {
            $canonicalHeadersString .= "{$name}:{$value}\n";
        }
        $signedHeadersList = implode(';', array_keys($normalized));

        $canonicalRequest = "POST\n/v2/email/outbound-emails\n{$canonicalQuery}\n{$canonicalHeadersString}\n{$signedHeadersList}\n{$hashedPayload}";

        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$isoTimestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->config->secretAccessKey, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = bin2hex(hash_hmac('sha256', $stringToSign, $kSigning, true));

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->config->accessKeyId,
            $credentialScope,
            $signedHeadersList,
            $signature,
        );

        $out = [
            'Host' => $host,
            'X-Amz-Date' => $isoTimestamp,
            'X-Amz-Content-Sha256' => $hashedPayload,
            'Authorization' => $authorization,
        ];
        if ($this->config->sessionToken !== null) {
            $out['X-Amz-Security-Token'] = $this->config->sessionToken;
        }

        return $out;
    }

    /**
     * Build the SigV4 canonical query string: keys sorted, each key/value
     * rawurlencoded, joined `k=v&...`. Identical encoding is appended to the
     * request URL so URL and signature agree.
     *
     * @param array<string,mixed> $query
     */
    private function buildCanonicalQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }
        // SigV4 requires sorting by the ENCODED key — encode first, then sort the
        // encoded `k=v` pairs (mirrors SnsSmsConnector::buildCanonicalQueryString).
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[rawurlencode((string) $key)] = rawurlencode((string) $value);
        }
        ksort($pairs);

        $out = [];
        foreach ($pairs as $k => $v) {
            $out[] = "{$k}={$v}";
        }

        return implode('&', $out);
    }

    /**
     * Override seam for deterministic signature testing.
     */
    protected function getTimestamp(): string
    {
        return gmdate('Ymd\\THis\\Z');
    }

    private function host(): string
    {
        return "email.{$this->config->region}.amazonaws.com";
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $type = '';
        if (is_array($body)) {
            $candidates = [$body['__type'] ?? null, $body['type'] ?? null, $body['Code'] ?? null];
            foreach ($candidates as $c) {
                if (is_string($c) && $c !== '') {
                    $type = $c;
                    break;
                }
            }
        }
        // SES sometimes formats `__type` as `com.amazonaws.services.ses#FooException`.
        $code = '';
        if ($type !== '') {
            $parts = explode('#', $type);
            $code = (string) end($parts);
        }

        $vendorMessage = 'SES HTTP ' . $statusCode;
        if (is_array($body)) {
            if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                $vendorMessage = $body['message'];
            } elseif (isset($body['Message']) && is_string($body['Message']) && $body['Message'] !== '') {
                $vendorMessage = $body['Message'];
            }
        }

        $providerCode = $this->classifyError($statusCode, $code);

        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $providerMessage = $parsedSeconds !== null
            ? "{$vendorMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $vendorMessage;

        $cause = [
            'raw' => $body,
            'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
            'retryAfterSeconds' => $parsedSeconds,
        ];

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            cause: $cause,
            statusCode: $statusCode,
        );
    }

    private function classifyError(int $statusCode, string $code): ProviderCode
    {
        // Body-layer error-code map takes precedence over HTTP-status defaults.
        switch ($code) {
            case 'MessageRejected':
            case 'InvalidEmailAddress':
                return ProviderCode::InvalidRecipient;
            case 'MailFromDomainNotVerified':
            case 'MailFromDomainNotVerifiedException':
            case 'FromEmailAddressNotVerified':
            case 'FromEmailAddressNotVerifiedException':
            case 'ValidationException':
            case 'InvalidParameter':
            case 'InvalidParameterValue':
            case 'BadRequestException':
                return ProviderCode::InvalidRequest;
            case 'AccountSendingPaused':
            case 'AccountSendingPausedException':
            case 'AccountSuspended':
            case 'AccountSuspendedException':
            case 'SignatureDoesNotMatch':
            case 'InvalidClientTokenId':
            case 'AccessDenied':
            case 'NotAuthorized':
                return ProviderCode::AuthFailed;
            case 'Throttling':
            case 'ThrottlingException':
            case 'TooManyRequestsException':
                return ProviderCode::RateLimited;
            case 'ServiceUnavailable':
            case 'InternalFailure':
                return ProviderCode::ProviderUnavailable;
        }

        // HTTP-layer defaults.
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
