<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Ses;

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
        #[\SensitiveParameter]
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
        $this->assertRegion();
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

        try {
            $serializedBody = json_encode($mergedBody, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Keep the error contract: a bad body (e.g. invalid UTF-8) must surface
            // as a ConnectorError, not a raw \JsonException the consumer can't catch
            // through the documented type.
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'SES: failed to encode request body',
                statusCode: null,
                cause: ['raw' => ['exception' => $e::class], 'retryAfter' => null],
            );
        }
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
     * SES v2 `SendEmail` rejects these as custom headers on Simple content:
     * "the following headers are disallowed as custom headers: BCC, CC,
     * Content-Disposition, Content-Type, Date, From, Message-ID, MIME-Version,
     * Reply-To, Return-Path, Subject, To" (SES developer guide, header-fields).
     *
     * @var array<string,true>
     */
    private const DISALLOWED_CUSTOM_HEADERS = [
        'bcc' => true,
        'cc' => true,
        'content-disposition' => true,
        'content-type' => true,
        'date' => true,
        'from' => true,
        'message-id' => true,
        'mime-version' => true,
        'reply-to' => true,
        'return-path' => true,
        'subject' => true,
        'to' => true,
    ];

    /** sesv2 `MessageHeaderList`: "Can contain no more than 15 headers in one message." */
    private const MAX_CUSTOM_HEADERS = 15;

    /**
     * Build the SES v2 SendEmail JSON body from `EmailSendInput`. PascalCase
     * keys are written directly in the array literal per the casing-locality rule.
     * `null` values are stripped after Passthrough merge (SES rejects nulls in
     * nested structures with `ValidationException`).
     *
     * Everything goes through `Content.Simple`: its `Headers` and `Attachments`
     * members (inline images via `ContentId` + `ContentDisposition: INLINE`)
     * cover custom headers and attachments, so SES builds and encodes the MIME
     * itself. `tags` map to `EmailTags: [{Name, Value}]`.
     *
     * @return array<string,mixed>
     */
    private function buildSesV2Body(EmailSendInput $input): array
    {
        $simple = [
            'Subject' => ['Data' => $input->subject, 'Charset' => 'UTF-8'],
            'Body' => [
                'Html' => $input->html !== null ? ['Data' => $input->html, 'Charset' => 'UTF-8'] : null,
                'Text' => $input->text !== null ? ['Data' => $input->text, 'Charset' => 'UTF-8'] : null,
            ],
        ];
        if ($input->headers !== null && $input->headers !== []) {
            $simple['Headers'] = $this->buildHeaders($input->headers);
        }
        if ($input->attachments !== null && $input->attachments !== []) {
            $simple['Attachments'] = $this->buildAttachments($input->attachments);
        }

        $body = [
            'FromEmailAddress' => $this->resolveFrom($input),
            'Destination' => [
                'ToAddresses' => [$this->mailbox($input->to)],
                'CcAddresses' => $input->cc !== null ? array_map($this->mailbox(...), $input->cc) : null,
                'BccAddresses' => $input->bcc !== null ? array_map($this->mailbox(...), $input->bcc) : null,
            ],
            'ReplyToAddresses' => $input->replyTo !== null ? [$this->mailbox($input->replyTo)] : null,
            'Content' => ['Simple' => $simple],
        ];

        $emailTags = $this->liftTags($input->tags);
        if ($emailTags !== []) {
            $body['EmailTags'] = $emailTags;
        }

        return $body;
    }

    /**
     * Resolve the sender: per-message `from` wins, else `config.from`, with
     * `config.senderName` as the display name.
     */
    private function resolveFrom(EmailSendInput $input): string
    {
        $address = $input->from !== '' ? $input->from : (string) $this->config->from;

        return $this->mailbox($address, $this->config->senderName);
    }

    /**
     * Format one address for SES: the display name is quoted or encoded as
     * SES requires for a "friendly from" name; the address itself is sent as
     * given.
     */
    private function mailbox(string $address, ?string $name = null): string
    {
        return Mailbox::format($name, $address);
    }

    /**
     * SES expects `EmailTags: [{Name, Value}]`. The base `EmailSendInput.tags`
     * is `list<string>`; each bare tag becomes `{Name: tag, Value: tag}`
     * (a documented design decision, mirrors TS `liftSesTags`).
     *
     * sesv2 `MessageTag` Name and Value: "It can only contain ASCII letters
     * (a–z, A–Z), numbers (0–9), underscores (_), or dashes (-). It can contain
     * no more than 256 characters." — checked here so the caller learns which
     * tag is wrong instead of getting a bare 400.
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

        $out = [];
        foreach ($tags as $tag) {
            if (preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $tag) !== 1) {
                throw new ConnectorError(
                    providerCode: ProviderCode::InvalidRequest,
                    providerMessage: "SES: tag '{$tag}' may only contain ASCII letters, digits, '_' or '-' (max 256)",
                    statusCode: 400,
                );
            }
            $out[] = ['Name' => $tag, 'Value' => $tag];
        }

        return $out;
    }

    /**
     * Map baseline `headers` onto `Content.Simple.Headers`, enforcing the
     * sesv2 `MessageHeader` rules before sending: Name "any printable ASCII
     * character (33 - 126) except for colon (:)", at most 126 characters; Value
     * "any printable ASCII character", at most 995; name plus value at most
     * 996; at most 15 headers. A non-ASCII value is sent as RFC 2047
     * encoded-words, the MIME way to carry it in printable ASCII; the limits
     * apply to the encoded form. A header SES sets itself (see
     * DISALLOWED_CUSTOM_HEADERS) is rejected — use the matching input field.
     * Values are never echoed in the error: a custom header can carry a token.
     *
     * @param array<string,string> $headers
     *
     * @return list<array{Name:string,Value:string}>
     */
    private function buildHeaders(array $headers): array
    {
        if (count($headers) > self::MAX_CUSTOM_HEADERS) {
            throw $this->invalidHeader('SES accepts at most 15 custom headers');
        }

        $out = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            if (preg_match('/^[!-9;-~]{1,126}$/D', $name) !== 1) {
                throw $this->invalidHeader('SES: header names must be 1-126 printable ASCII characters without a colon');
            }
            if (isset(self::DISALLOWED_CUSTOM_HEADERS[strtolower($name)])) {
                throw $this->invalidHeader("SES sets the '{$name}' header itself; use the matching input field instead");
            }
            if (!Mailbox::isAscii($value)) {
                $value = Mailbox::encodedWords($value);
            }
            if ($value === '' || preg_match('/^[ -~]{1,995}$/D', $value) !== 1) {
                throw $this->invalidHeader("SES: header '{$name}' value must be 1-995 printable ASCII characters");
            }
            if (strlen($name) + strlen($value) > 996) {
                throw $this->invalidHeader("SES: header '{$name}' name plus value exceeds 996 characters");
            }
            $out[] = ['Name' => $name, 'Value' => $value];
        }

        return $out;
    }

    private function invalidHeader(string $message): ConnectorError
    {
        return new ConnectorError(
            providerCode: ProviderCode::InvalidRequest,
            providerMessage: $message,
            statusCode: 400,
        );
    }

    /**
     * sesv2 `Attachment`: `RawContent` ("needs to be base64-encoded if you are
     * accessing Amazon SES directly through the HTTPS interface") and
     * `FileName` are required; `ContentId` is "used for referencing
     * attachments with INLINE disposition in HTML content".
     *
     * @param list<EmailAttachment> $attachments
     *
     * @return list<array<string,string>>
     */
    private function buildAttachments(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $attachment) {
            $entry = [
                'FileName' => $attachment->filename,
                'RawContent' => base64_encode($this->normalizeAttachmentContent($attachment)),
            ];
            if ($attachment->contentType !== null && $attachment->contentType !== '') {
                $entry['ContentType'] = $attachment->contentType;
            }
            if ($attachment->contentId !== null && $attachment->contentId !== '') {
                $entry['ContentId'] = $attachment->contentId;
                $entry['ContentDisposition'] = 'INLINE';
            } else {
                $entry['ContentDisposition'] = 'ATTACHMENT';
            }
            $out[] = $entry;
        }

        return $out;
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
    private function signRequest(
        #[\SensitiveParameter]
        string $serializedJsonBody,
        #[\SensitiveParameter]
        array $additionalSignedHeaders,
        string $isoTimestamp,
        string $canonicalQuery = '',
    ): array {
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
     * The region is interpolated into the host the signed request (and any
     * session token) is sent to, so a value like `x.evil.test/` must never
     * reach the wire. Same shape check as botocore `validate_region_name`.
     */
    private function assertRegion(): void
    {
        if (preg_match('/^(?![0-9]+$)(?!-)[a-zA-Z0-9-]{1,63}(?<!-)$/D', $this->config->region) !== 1) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'SES: region must be an AWS region name such as us-east-1',
                statusCode: 400,
            );
        }
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $code = self::errorCode($response, $body);

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

    /**
     * The restJson1 error code, resolved the way the AWS SDKs do (botocore
     * `RestJSONParser._inject_error_code`, aws-sdk-js-v3
     * `loadRestJsonErrorCode`): the `x-amzn-ErrorType` header first, then body
     * `code`/`Code`, then `__type`. Six SendEmail errors share HTTP 400, so the
     * code is the only way to tell them apart. The header looks like
     * `MessageRejected:http://internal.amazon.com/...` and `__type` like
     * `com.amazonaws...#MessageRejected`, so the value is cut at `,` and `:` and
     * the part after the last `#` is kept.
     *
     * @param array<string,mixed>|null $body
     */
    private static function errorCode(ResponseInterface $response, ?array $body): string
    {
        $candidates = [$response->getHeaderLine('x-amzn-ErrorType')];
        if (is_array($body)) {
            array_push($candidates, $body['code'] ?? null, $body['Code'] ?? null, $body['__type'] ?? null, $body['type'] ?? null);
        }
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $code = explode(',', $candidate, 2)[0];
            $code = explode(':', $code, 2)[0];
            $hash = strrpos($code, '#');
            $code = trim($hash === false ? $code : substr($code, $hash + 1));
            if ($code !== '') {
                return $code;
            }
        }

        return '';
    }

    private function classifyError(int $statusCode, string $code): ProviderCode
    {
        // Body-layer error-code map takes precedence over HTTP-status defaults.
        switch ($code) {
            case 'InvalidEmailAddress':
                return ProviderCode::InvalidRecipient;
            case 'MessageRejected': // SendEmail: "The message can't be sent because it contains invalid content."
            case 'MailFromDomainNotVerified':
            case 'MailFromDomainNotVerifiedException':
            case 'FromEmailAddressNotVerified':
            case 'FromEmailAddressNotVerifiedException':
            case 'ValidationException':
            case 'InvalidParameter':
            case 'InvalidParameterValue':
            case 'BadRequestException':
                return ProviderCode::InvalidRequest;
            case 'SendingPausedException': // SendEmail: "currently paused"; the v1 AccountSendingPaused* names stay as aliases
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
